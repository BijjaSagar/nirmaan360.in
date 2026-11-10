import UserNotifications

/// Shows the picture of an announcement on iPhone.
///
/// iOS draws only the title and text of a push by itself. The server marks a
/// push that has a picture with `mutable-content: 1` and puts the address in
/// `fcm_options.image` (see `FcmGateway::payload`), which wakes this extension:
/// it downloads the picture and attaches it before the notification is shown.
/// Anything that goes wrong, or takes longer than iOS allows, shows the
/// notification as it came, without the picture. Needs no Firebase code.
final class NotificationService: UNNotificationServiceExtension {
    private let lock = NSLock()
    private var contentHandler: ((UNNotificationContent) -> Void)?
    private var content: UNMutableNotificationContent?
    private var download: URLSessionDownloadTask?

    override func didReceive(
        _ request: UNNotificationRequest,
        withContentHandler contentHandler: @escaping (UNNotificationContent) -> Void
    ) {
        guard let content = request.content.mutableCopy() as? UNMutableNotificationContent,
              let url = Self.imageURL(in: content.userInfo) else {
            contentHandler(request.content)
            return
        }

        lock.lock()
        self.contentHandler = contentHandler
        self.content = content
        lock.unlock()

        let task = URLSession.shared.downloadTask(with: url) { [weak self] location, response, error in
            guard let self else { return }
            if error == nil, let location, let attachment = Self.attachment(from: location, response: response) {
                content.attachments = [attachment]
            }
            self.finish()
        }
        lock.lock()
        download = task
        lock.unlock()
        task.resume()
    }

    /// iOS gives an extension about 30 seconds. Show the text rather than nothing.
    override func serviceExtensionTimeWillExpire() {
        lock.lock()
        let task = download
        lock.unlock()
        task?.cancel()
        finish()
    }

    /// Hands the notification to iOS once, whichever of the download or the deadline comes first.
    private func finish() {
        lock.lock()
        let handler = contentHandler
        let shown = content
        contentHandler = nil
        lock.unlock()
        if let handler, let shown {
            handler(shown)
        }
    }

    /// The picture's address. Only https: iOS refuses plain http without an exception.
    static func imageURL(in userInfo: [AnyHashable: Any]) -> URL? {
        guard let options = userInfo["fcm_options"] as? [String: Any],
              let text = options["image"] as? String,
              let url = URL(string: text),
              url.scheme?.lowercased() == "https" else {
            return nil
        }
        return url
    }

    /// Moves the download to a file with the right extension, which iOS needs to read it as a picture.
    static func attachment(from location: URL, response: URLResponse?) -> UNNotificationAttachment? {
        if let http = response as? HTTPURLResponse, !(200..<300).contains(http.statusCode) {
            return nil
        }
        let suffix: String
        switch response?.mimeType?.lowercased() {
        case "image/png": suffix = "png"
        case "image/gif": suffix = "gif"
        default: suffix = "jpg"
        }
        let file = FileManager.default.temporaryDirectory
            .appendingPathComponent(UUID().uuidString)
            .appendingPathExtension(suffix)
        do {
            try FileManager.default.moveItem(at: location, to: file)
            return try UNNotificationAttachment(identifier: "image", url: file, options: nil)
        } catch {
            return nil
        }
    }
}
