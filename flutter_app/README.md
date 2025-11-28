# maintenance_webview_app (template)

This folder is a minimal Flutter app template (not a full generated project). It contains `lib/main.dart` and `pubspec.yaml` configured to open `https://maintenance.gt.tc` in a WebView.

What you should do next (Windows PowerShell):

1. Open PowerShell and change to this directory:

```powershell
cd c:\xampp\htdocs\maintenance\flutter_app
```

2. If you don't yet have a Flutter project here, run `flutter create .` to generate the platform directories and necessary files:

```powershell
flutter create .
```

3. Get dependencies:

```powershell
flutter pub get
```

4. (Optional) Ensure `webview_flutter` is added:

```powershell
flutter pub add webview_flutter
flutter pub get
```

5. Add Internet permission: open `android\app\src\main\AndroidManifest.xml` and add the following line inside the `<manifest>` tag, before `<application>`:

```xml
<uses-permission android:name="android.permission.INTERNET"/>
```

6. If your Android `minSdkVersion` is lower than 19, edit `android\app\build.gradle` and set `minSdkVersion 19` in `defaultConfig`.

7. Build the release APK:

```powershell
flutter build apk --release
```

8. Install on a connected device (ADB must be available):

```powershell
adb install -r build\app\outputs\flutter-apk\app-release.apk
```

Notes:
- If your site only supports HTTP (not HTTPS), you'll need an Android network-security config to allow cleartext traffic for the domain. Ask me and I will add it.
- If you want me to create the full Flutter project (including `android/` and Gradle files) here, I can attempt to write them, but the `flutter create` command is recommended because it generates many files and correct platform configuration automatically.
