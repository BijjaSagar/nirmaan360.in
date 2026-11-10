# Google Play release

How the field app goes on Google Play, and everything Play Console asks for. The store
graphics are in `store/play/`. `tool/store_graphics.sh` rebuilds the icon and banner.

## Build

```
cd mobile
flutter build appbundle --release --dart-define-from-file=firebase.defines.json
```

The bundle lands in `build/app/outputs/bundle/release/app-release.aab`. It is signed with the
Play upload key when Gradle finds `android/key.properties` or `~/.nirmaan360/upload-key.properties`.
Without either, it falls back to the debug key and Play rejects it. Neither file, the keystore
nor `firebase.defines.json` may ever be committed: the repository is public.

Before uploading, check:

- `keytool -printcert -jarfile app-release.aab` names `CN=Nirmaan360`, not `Android Debug`.
- The version code is higher than the last one uploaded. Play refuses the same number twice.
- 64-bit native libraries are aligned to 16 KB, which Play requires for Android 15 and later.
  `llvm-objdump -p` shows `align 2**14` or more on every `LOAD` line.

Today's numbers: application id `in.nirmaan360.app`, minimum Android 8.0 (API 26), target API 36.

### The upload key

With Play App Signing, Google holds the key that signs what phones install. We hold only the
upload key. If the upload key is ever lost, the account owner can ask for an upload key reset
in Play Console (Setup → App signing). That is slow, but the app is not lost. Keep a backup of
`~/.nirmaan360/` (keystore and its properties file) somewhere private, never in the repository
or a shared chat.

### Phones that have the shared APK

The APK shared from phone to phone is signed with the debug key. The Play version is signed by
Google's key, so Android will not update one into the other. A phone moving to the Play version
must first send everything in the Outbox (the top bar shows "All sent"), then uninstall the
shared APK and install from Play. Anything still unsent is lost when the app is uninstalled.

## Play Console, first time

1. **Create app.** Name `Nirmaan360`, default language English (India) `en-IN`, App, Free.
   Accept the declarations.
2. **Upload to Internal testing first.** Testing → Internal testing → Create release, accept
   Play App Signing (Google-generated key), upload the `.aab`, add testers by email, roll out.
   Internal testing needs no review and reaches testers in minutes.
3. **Production access.** A personal developer account created after November 2023 must run a
   closed test with at least 12 testers who stay opted in for 14 days in a row before Play
   allows production. An organisation account (registered with a D-U-N-S number) does not have
   this rule. Check which kind the account is under Settings → Developer account.
4. **App content** (Policy → App content). The answers are below.
5. **Store listing.** The text and graphics are below.

## App content answers

**Privacy policy:** `https://nirmaan360.in/privacy`. Before submitting, it has to mention two
things it does not yet say: the location read when an attendance photo is taken, and the
notification token sent for alerts. The text can be changed in Platform admin without a deploy
(site setting `privacy_text`).

**App access:** All functionality is behind sign-in. Give reviewers a working email and
password for a demo company on nirmaan360.in, with at least one project, and note:
"Choose a project, then open Daily report, Measure and Approvals." Create that demo company on
the live site yourself; it must stay working while the app is in review and afterwards.

**Ads:** No ads.

**Content rating:** Questionnaire category "All other app types". No violence, sexual content,
language, controlled substances, gambling or user-to-user sharing with the public. It comes out
as Everyone / 3+.

**Target audience:** 18 and over. Not designed for children.

**News app:** No. **Government app:** No. **Health:** No.
**Financial features:** "My app doesn't provide any financial features". It records bills and
contractor payments for a company's own books; it does not lend, pay, trade or hold money.

**Foreground services:** The app does not start a foreground service itself. The background
sync library (WorkManager) declares the "short service" type. If Play asks, answer that it is
used by WorkManager for background upload of saved entries.

**Data safety.** Data is encrypted in transit (HTTPS). Users can ask for deletion through
`https://nirmaan360.in/contact` (the privacy policy says so). No data is sold or shared with
third parties; Firebase delivers notifications as a service provider, which Play does not count
as sharing.

| Data type | Collected | Why | Required |
|---|---|---|---|
| Name | Yes | Account management, App functionality | Required |
| Email address | Yes | Account management (sign-in) | Required |
| Precise location | Yes, once per attendance photo, never in the background | App functionality (where an attendance photo was taken) | Required for photo attendance |
| Photos | Yes | App functionality (site, report, bill and attendance photos) | Optional |
| Other user-generated content | Yes (reports, measurements, remarks, bills) | App functionality | Required |
| Device or other IDs | Yes (notification token) | App functionality (alerts) | Optional, only after the person agrees to alerts |
| App activity, crash logs, diagnostics, contacts, messages, audio, health, financial info | No | | |

The app has no analytics or crash-reporting library. Camera use: taking photos only. Location
is never read in the background and the app does not request background location.

## Store listing

**App name** (30 characters at most): `Nirmaan360 – Site to Bill`

**Category:** Business. **Contact email:** the company's support address. **Website:**
`https://nirmaan360.in`

### English (en-IN)

**Short description** (80 at most):

> Daily reports, measurements, approvals and attendance for construction sites.

**Full description:**

> Nirmaan360 puts your construction site in your pocket: from the day's work to the bill.
>
> Made for site engineers, project managers and contractors who already use Nirmaan360 on the web. Sign in with the account your company gives you.
>
> DAILY REPORT IN A MINUTE
> Weather, workers by trade, work done against BOQ lines, and photos. Copy yesterday's labour with one tap.
>
> MEASUREMENT BOOK
> Enter Nos × L × B × D on a large keypad made for gloved hands. See the quantity, the BOQ balance after it, and a warning when it runs over.
>
> APPROVALS ON THE GO
> Approve or send back daily reports, measurements, bills and contractor payments, with the full document in front of you.
>
> ATTENDANCE
> Mark workers present, with a photo and the place it was taken.
>
> MATERIAL AND CONTRACTORS
> Record material received, answer delivery questions, and see work done against each contract.
>
> WORKS WITHOUT NETWORK
> Everything is saved on the phone first and sent when the network returns. Nothing is lost on a basement floor.
>
> ALERTS THAT MATTER
> Get told when something waits for you, and choose which alerts you want and when to stay quiet.
>
> English, मराठी and हिंदी.

### मराठी (mr-IN)

**Short description:**

> बांधकाम साइटसाठी दैनिक अहवाल, मोजमाप, मंजुरी आणि हजेरी — फोनवरूनच.

**Full description:**

> Nirmaan360 मुळे तुमची बांधकाम साइट तुमच्या खिशात येते: दिवसाच्या कामापासून बिलापर्यंत.
>
> वेबवर Nirmaan360 वापरणाऱ्या साइट इंजिनियर, प्रोजेक्ट मॅनेजर आणि ठेकेदारांसाठी. तुमच्या कंपनीने दिलेल्या खात्याने साइन इन करा.
>
> एका मिनिटात दैनिक अहवाल
> हवामान, कामानुसार मजूर, BOQ नुसार झालेले काम आणि फोटो. कालची मजूर संख्या एका टॅपमध्ये.
>
> मोजमाप पुस्तक
> मोठ्या कीपॅडवर नग × लां × रुं × खो भरा. प्रमाण, त्यानंतर उरलेले BOQ प्रमाण आणि जास्त झाल्यास सूचना लगेच दिसते.
>
> कुठूनही मंजुरी
> दैनिक अहवाल, मोजमाप, बिले आणि ठेकेदार पेमेंट — पूर्ण कागद पाहून मंजूर करा किंवा परत पाठवा.
>
> हजेरी
> मजुरांची हजेरी फोटो आणि ठिकाणासह.
>
> मटेरियल आणि ठेकेदार
> आलेले मटेरियल नोंदवा, डिलिव्हरीचे प्रश्न सोडवा, प्रत्येक करारानुसार झालेले काम पहा.
>
> नेटवर्क नसतानाही चालते
> सगळे आधी फोनवर जतन होते आणि नेटवर्क आल्यावर पाठवले जाते.
>
> गरजेच्या सूचना
> तुमच्यासाठी काही थांबले असेल तर लगेच कळते. कोणत्या सूचना हव्या आणि कधी शांत राहायचे ते तुम्ही ठरवा.
>
> English, मराठी आणि हिंदी.

### हिंदी (hi-IN)

**Short description:**

> कंस्ट्रक्शन साइट के लिए दैनिक रिपोर्ट, माप, मंज़ूरी और हाज़िरी — फ़ोन से ही।

**Full description:**

> Nirmaan360 से आपकी कंस्ट्रक्शन साइट आपकी जेब में: दिन के काम से बिल तक।
>
> वेब पर Nirmaan360 इस्तेमाल करने वाले साइट इंजीनियर, प्रोजेक्ट मैनेजर और ठेकेदारों के लिए। अपनी कंपनी से मिले खाते से साइन इन करें।
>
> एक मिनट में दैनिक रिपोर्ट
> मौसम, काम के हिसाब से मज़दूर, BOQ के हिसाब से हुआ काम और फ़ोटो। कल की मज़दूर संख्या एक टैप में।
>
> माप पुस्तिका
> बड़े कीपैड पर नग × लं × चौ × गह भरें। मात्रा, उसके बाद बचा BOQ और ज़्यादा होने पर चेतावनी तुरंत दिखती है।
>
> कहीं से भी मंज़ूरी
> दैनिक रिपोर्ट, माप, बिल और ठेकेदार भुगतान — पूरा दस्तावेज़ देखकर मंज़ूर करें या वापस भेजें।
>
> हाज़िरी
> मज़दूरों की हाज़िरी फ़ोटो और जगह के साथ।
>
> मटेरियल और ठेकेदार
> आया हुआ मटेरियल दर्ज करें, डिलीवरी के सवालों का जवाब दें, हर ठेके के हिसाब से हुआ काम देखें।
>
> नेटवर्क न हो तब भी चलता है
> सब कुछ पहले फ़ोन पर सेव होता है और नेटवर्क आने पर भेजा जाता है।
>
> ज़रूरी सूचनाएँ
> आपके लिए कुछ रुका हो तो तुरंत पता चलता है। कौन-सी सूचनाएँ चाहिए और कब शांत रहना है, आप तय करें।
>
> English, मराठी और हिंदी।

### Graphics

| Play asks for | File |
|---|---|
| App icon, 512 × 512 | `store/play/icon-512.png` |
| Feature graphic, 1024 × 500 | `store/play/feature-graphic.jpg` |
| Phone screenshots, 2 to 8 | `store/play/screenshots/*.png` (1080 × 1920) |

The screenshots come from the app on an emulator signed in to local demo data, not from a live
company. Retake them when a screen changes noticeably.
