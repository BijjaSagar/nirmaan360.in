import Foundation
import UserNotifications

// Checks NotificationService outside Xcode, on this Mac: the push is never sent, the download is
// answered by a stub, and each case counts how often iOS would be handed the notification.
// Run from mobile/:  tool/check_notification_service.sh

/// Answers every request from the stub table instead of the network. URLSession.shared uses it once registered.
final class StubProtocol: URLProtocol {
    static var answers: [String: (status: Int, mime: String, body: Data, delay: TimeInterval)] = [:]
    static var asked: [String] = []

    override class func canInit(with request: URLRequest) -> Bool { true }
    override class func canonicalRequest(for request: URLRequest) -> URLRequest { request }

    override func startLoading() {
        let url = request.url!.absoluteString
        StubProtocol.asked.append(url)
        guard let answer = StubProtocol.answers[url] else {
            client?.urlProtocol(self, didFailWithError: URLError(.cannotFindHost))
            return
        }
        DispatchQueue.global().asyncAfter(deadline: .now() + answer.delay) { [self] in
            let response = HTTPURLResponse(url: request.url!, statusCode: answer.status, httpVersion: "HTTP/1.1", headerFields: ["Content-Type": answer.mime])!
            client?.urlProtocol(self, didReceive: response, cacheStoragePolicy: .notAllowed)
            client?.urlProtocol(self, didLoad: answer.body)
            client?.urlProtocolDidFinishLoading(self)
        }
    }

    override func stopLoading() {}
}

/// A real 1x1 PNG, so UNNotificationAttachment accepts it as a picture.
let png = Data(base64Encoded: "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==")!

var failures = 0

func check(_ ok: Bool, _ what: String) {
    print((ok ? "ok    " : "FAIL  ") + what)
    if !ok { failures += 1 }
}

/// Runs the extension on one push and waits for it to hand the notification back.
func deliver(_ userInfo: [AnyHashable: Any], expireAfter: TimeInterval? = nil) -> (calls: Int, attachments: Int) {
    let content = UNMutableNotificationContent()
    content.title = "Safety week starts Monday"
    content.body = "Wear a helmet on every site."
    content.userInfo = userInfo
    let request = UNNotificationRequest(identifier: UUID().uuidString, content: content, trigger: nil)

    let service = NotificationService()
    let done = DispatchSemaphore(value: 0)
    var calls = 0
    var attachments = 0
    let lock = NSLock()

    service.didReceive(request) { shown in
        lock.lock()
        calls += 1
        attachments = shown.attachments.count
        lock.unlock()
        done.signal()
    }
    if let expireAfter {
        Thread.sleep(forTimeInterval: expireAfter)
        service.serviceExtensionTimeWillExpire()
    }
    _ = done.wait(timeout: .now() + 10)
    // Give a late download the chance to call again, which it must not.
    Thread.sleep(forTimeInterval: 1.5)
    lock.lock()
    defer { lock.unlock() }
    return (calls, attachments)
}

URLProtocol.registerClass(StubProtocol.self)
let image = "https://nirmaan360.test/announcement-images/abc"
StubProtocol.answers = [
    image: (200, "image/png", png, 0),
    "https://nirmaan360.test/missing": (404, "text/html", Data("<h1>Not found</h1>".utf8), 0),
    "https://nirmaan360.test/slow": (200, "image/png", png, 1.0),
]

// Where FCM puts apns.fcm_options.image in the payload iOS receives.
check(NotificationService.imageURL(in: ["fcm_options": ["image": image]])?.absoluteString == image, "reads fcm_options.image")
check(NotificationService.imageURL(in: ["fcm_options": ["image": "http://nirmaan360.test/a.png"]]) == nil, "refuses plain http")
check(NotificationService.imageURL(in: ["fcm_options": ["image": 42]]) == nil, "ignores a value that is not text")
check(NotificationService.imageURL(in: ["type": "announcement"]) == nil, "no picture, no address")

var result = deliver(["type": "announcement", "id": "1"])
check(result.calls == 1 && result.attachments == 0, "a push without a picture is shown once, as it came")
check(StubProtocol.asked.isEmpty, "and nothing is downloaded for it")

result = deliver(["type": "announcement", "id": "1", "fcm_options": ["image": image]])
check(result.calls == 1 && result.attachments == 1, "a push with a picture is shown once, with the picture attached")

result = deliver(["fcm_options": ["image": "https://nirmaan360.test/missing"]])
check(result.calls == 1 && result.attachments == 0, "a 404 shows the text alone, once")

result = deliver(["fcm_options": ["image": "https://nirmaan360.test/nowhere"]])
check(result.calls == 1 && result.attachments == 0, "a failed download shows the text alone, once")

result = deliver(["fcm_options": ["image": "https://nirmaan360.test/slow"]], expireAfter: 0.2)
check(result.calls == 1 && result.attachments == 0, "when iOS's time runs out first, the text is shown once and the late picture is dropped")

print(failures == 0 ? "All checks passed." : "\(failures) checks failed.")
exit(failures == 0 ? 0 : 1)
