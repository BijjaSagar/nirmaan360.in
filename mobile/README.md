# Nirmaan360 field app

Flutter app for site engineers: daily reports, measurement book, attendance, issues, petty cash and approvals. It works offline for 7 days and syncs when the network returns. See [docs/CONTRACT.md](docs/CONTRACT.md) for the API, offline rules and design tokens.

## Run

From the repository root, start a local API:

```bash
php artisan migrate --seed
```

```bash
php artisan serve --port=8361
```

Then from `mobile/`:

```bash
flutter run --dart-define=API_BASE_URL=http://127.0.0.1:8361/api/v1
```

An Android emulator reaches the Mac at `http://10.0.2.2:8361/api/v1`. Demo users are in `database/seeders/DemoSeeder.php` and exist only on local data. Release builds default to `https://nirmaan360.in/api/v1`.

## Check

```bash
flutter analyze
```

```bash
flutter test
```

To run the offline stack against a local server (never production):

```bash
flutter test test/integration/real_api_test.dart --dart-define=REAL_API_URL=http://127.0.0.1:8361/api/v1
```

## Push notifications

Push is off unless the build is given the Firebase settings. Put the two console files anywhere outside the repo, then from `mobile/`:

```bash
dart run tool/firebase_defines.dart --android ~/Downloads/google-services.json --ios ~/Downloads/GoogleService-Info.plist
flutter build apk --dart-define-from-file=firebase.defines.json
flutter build ipa --dart-define-from-file=firebase.defines.json
```

`firebase.defines.json` is git-ignored and the script prints none of its values. iOS also needs the APNs key uploaded in the Firebase console. See "Push notifications" in [docs/CONTRACT.md](docs/CONTRACT.md).

## Translations

Add strings to `l10n/<area>.json` with `en`, `mr` and `hi` side by side, then:

```bash
dart run tool/build_l10n.dart && flutter gen-l10n
```

## Release notes

- Raise the build number in `pubspec.yaml` and `lib/app/app_info.dart` before every upload.
- On Xcode 27, `flutter build ios --simulator` fails because `lipo -verify_arch` rejects two architectures. `flutter run` on a simulator and `flutter build ipa` both work.
- Xcode 27 supports iOS 15 and later, so the minimum is iOS 15.
- The release APK is signed with the debug key. Add a real signing key before publishing to Google Play.
