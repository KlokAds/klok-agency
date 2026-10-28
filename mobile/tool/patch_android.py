"""Turns the android/ folder that `flutter create` makes into KlokOne's app: the package
name, Firebase, signing (the same key every time, so a new APK installs over the old one),
permissions, our Kotlin and the notification icon. Run from mobile/.

`python3 tool/patch_android.py` makes the plain app (no SMS permission: Play Protect lets it
install). Run again with `sms` afterwards and the same folder becomes the SMS app for the
Owner's phone: the SMS permissions and the receiver are added, nothing else changes."""

import re
import shutil
import sys
from pathlib import Path


def patch(path, old, new, count=1):
    text = Path(path).read_text(encoding='utf8')
    if not re.search(old, text):
        raise SystemExit(f'{path}: pattern not found: {old}')
    Path(path).write_text(re.sub(old, new, text, count=count), encoding='utf8')


manifest = 'android/app/src/main/AndroidManifest.xml'

if sys.argv[1:] == ['sms']:
    patch(manifest, r'(<uses-permission android:name="android.permission.INTERNET" />)', r'''\1
    <uses-permission android:name="android.permission.RECEIVE_SMS" />
    <uses-permission android:name="android.permission.READ_SMS" />
    <uses-permission android:name="android.permission.REQUEST_IGNORE_BATTERY_OPTIMIZATIONS" />''')
    # The SMS receiver works with the app closed.
    patch(manifest, r'(\n(\s*)<activity)', r'''
        <receiver android:name=".SmsReceiver" android:exported="true" android:permission="android.permission.BROADCAST_SMS">
            <intent-filter android:priority="999">
                <action android:name="android.provider.Telephony.SMS_RECEIVED" />
            </intent-filter>
        </receiver>\1''')
    # NSave (the USD card) tells of card moves in its app's notifications, not by SMS: this
    # listener hands them to the SMS forwarder (android-src/NSaveListener.kt). Android binds it
    # only once the Owner allows notification access. Added once, however often this runs.
    if '.NSaveListener' not in Path(manifest).read_text(encoding='utf8'):
        patch(manifest, r'(\n(\s*)<activity)', r'''
        <service android:name=".NSaveListener" android:exported="true" android:permission="android.permission.BIND_NOTIFICATION_LISTENER_SERVICE">
            <intent-filter>
                <action android:name="android.service.notification.NotificationListenerService" />
            </intent-filter>
        </service>\1''')
        # Android 11+ hides other apps' names unless asked: launcher apps are visible, so NSave
        # is found by its name too when its package name does not say "nsave".
        patch(manifest, r'(<queries>)', r'''\1
        <intent>
            <action android:name="android.intent.action.MAIN" />
            <category android:name="android.intent.category.LAUNCHER" />
        </intent>''')
    print('android patched: sms')
    sys.exit(0)

settings = 'android/settings.gradle.kts'
patch(settings, r'(\n(\s*)id\("com\.android\.application"\)[^\n]*)',
      r'\1\n\2id("com.google.gms.google-services") version "4.4.2" apply false')

app = 'android/app/build.gradle.kts'
patch(app, r'(\n(\s*)id\("com\.android\.application"\))', r'\1\n\2id("com.google.gms.google-services")')
patch(app, r'minSdk = [^\n]+', 'minSdk = 23')
patch(app, r'compileOptions \{', 'compileOptions {\n        isCoreLibraryDesugaringEnabled = true')
patch(app, r'(\n(\s*)buildTypes \{)', r'''
    signingConfigs {
        create("release") {
            storeFile = file(System.getenv("KEYSTORE_FILE"))
            storePassword = System.getenv("KEYSTORE_PASSWORD")
            keyAlias = "khamar"
            keyPassword = System.getenv("KEYSTORE_PASSWORD")
        }
    }
\1''')
patch(app, r'signingConfig = signingConfigs\.getByName\("debug"\)', 'signingConfig = signingConfigs.getByName("release")')
# No code shrinking: R8 strips classes some plugins find by reflection (the notification
# plugin among them), and the app then closes as it opens. A few MB more is worth it.
patch(app, r'(signingConfig = signingConfigs\.getByName\("release"\))', r'''\1
            isMinifyEnabled = false
            isShrinkResources = false''')
Path(app).write_text(Path(app).read_text(encoding='utf8') + '''
dependencies {
    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")
    implementation("androidx.work:work-runtime-ktx:2.9.1")
}
''', encoding='utf8')

patch(manifest, r'(<manifest[^>]*>)', r'''\1
    <uses-permission android:name="android.permission.INTERNET" />
    <uses-permission android:name="android.permission.ACCESS_NETWORK_STATE" />
    <uses-permission android:name="android.permission.POST_NOTIFICATIONS" />
    <uses-permission android:name="android.permission.VIBRATE" />
    <uses-permission android:name="android.permission.WAKE_LOCK" />
    <uses-permission android:name="android.permission.USE_BIOMETRIC" />
    <uses-permission android:name="android.permission.RECEIVE_BOOT_COMPLETED" />
    <uses-permission android:name="android.permission.REQUEST_INSTALL_PACKAGES" />
    <uses-permission android:name="android.permission.UPDATE_PACKAGES_WITHOUT_USER_ACTION" />''')
# The notification plugin's receiver handles taps; ours hears how an update install went.
patch(manifest, r'(\n(\s*)<activity)', r'''
        <receiver android:exported="false" android:name=".UpdateReceiver" />
        <receiver android:exported="false" android:name="com.dexterous.flutterlocalnotifications.ActionBroadcastReceiver" />
        <meta-data android:name="com.google.firebase.messaging.default_notification_channel_id" android:value="finance_normal" />
        <meta-data android:name="com.google.firebase.messaging.default_notification_icon" android:resource="@drawable/ic_notification" />
        <meta-data android:name="com.google.firebase.messaging.default_notification_color" android:resource="@color/finance_teal" />\1''')
patch(manifest, r'android:label="[^"]*"', 'android:label="KlokOne"')
# Nothing of the app's own data (the SMS hook secret, the PIN, payment SMS) goes into phone backups.
# Nor into a phone-to-phone move: the whole data folder ("root") is left out, so the WebView's
# signed-in cookies and kept pages (app_webview/) stay behind and a new phone signs in fresh,
# instead of arriving signed in without the lock settings.
patch(manifest, r'(<application\s)', r'\1android:allowBackup="false" android:fullBackupContent="false" android:dataExtractionRules="@xml/data_extraction_rules" ')
Path('android/app/src/main/res/xml').mkdir(parents=True, exist_ok=True)
nothing = ''.join(f'<exclude domain="{d}" path="." />' for d in ('root', 'sharedpref', 'database', 'file', 'device_root'))
Path('android/app/src/main/res/xml/data_extraction_rules.xml').write_text(
    f'<?xml version="1.0" encoding="utf-8"?>\n<data-extraction-rules>\n    <cloud-backup>{nothing}</cloud-backup>\n    <device-transfer>{nothing}</device-transfer>\n</data-extraction-rules>\n',
    encoding='utf8')
# Links to billing.klokads.com (an email's button, a WhatsApp message) open in the app; the site's
# /.well-known/assetlinks.json says this app may. The download page stays in the browser.
patch(manifest, r'(<category android:name="android.intent.category.LAUNCHER"\s*/>\s*</intent-filter>)', r'''\1
            <intent-filter android:autoVerify="true">
                <action android:name="android.intent.action.VIEW" />
                <category android:name="android.intent.category.DEFAULT" />
                <category android:name="android.intent.category.BROWSABLE" />
                <data android:scheme="https" android:host="billing.klokads.com" />
                <data android:pathPrefix="/portal" />
                <data android:pathPrefix="/i/" />
                <data android:pathPrefix="/r/" />
                <data android:pathPrefix="/notifications" />
                <data android:pathPrefix="/dashboard" />
                <data android:pathPrefix="/invoices" />
                <data android:pathPrefix="/campaigns" />
                <data android:pathPrefix="/renewals" />
                <data android:pathPrefix="/clients" />
                <data android:pathPrefix="/settings" />
            </intent-filter>''')
# Our Application: reports a crash to the server (see android-src/CrashReporter.kt).
patch(manifest, r'android:name="\$\{applicationName\}"', 'android:name=".FinanceApplication"')
# Other apps (browser, phone, WhatsApp) for outside links.
patch(manifest, r'(<queries>)', r'''\1
        <intent>
            <action android:name="android.intent.action.VIEW" />
            <data android:scheme="https" />
        </intent>
        <intent>
            <action android:name="android.intent.action.DIAL" />
        </intent>''')

# The package Firebase knows the app by (flutter create would make com.klokads.klokfinance).
patch(app, r'namespace = "[^"]+"', 'namespace = "com.klokads.finance"')
patch(app, r'applicationId = "[^"]+"', 'applicationId = "com.klokads.finance"')

# Our own Kotlin in its place: the activity (fingerprint needs a FragmentActivity) and the
# SMS forwarder.
for made in Path('android/app/src/main/kotlin').rglob('MainActivity.kt'):
    made.unlink()
target = Path('android/app/src/main/kotlin/com/klokads/finance')
target.mkdir(parents=True, exist_ok=True)
for source in Path('android-src').glob('*.kt'):
    shutil.copy(source, target / source.name)

res = Path('android/app/src/main/res')
(res / 'drawable').mkdir(parents=True, exist_ok=True)
# The K mark in white on nothing: Android tints small notification icons itself.
(res / 'drawable' / 'ic_notification.xml').write_text('''<vector xmlns:android="http://schemas.android.com/apk/res/android"
    android:width="24dp" android:height="24dp" android:viewportWidth="64" android:viewportHeight="64">
    <path android:strokeColor="#FFFFFFFF" android:strokeWidth="7" android:strokeLineCap="round" android:strokeLineJoin="round"
        android:pathData="M18,12L18,52M18,34L34,14M25,27L39,52" />
    <path android:strokeColor="#FFFFFFFF" android:strokeWidth="5" android:strokeLineCap="round"
        android:pathData="M44,42L51,42M44,32L51,32M46,22L51,22" />
</vector>
''', encoding='utf8')
(res / 'values').mkdir(parents=True, exist_ok=True)
(res / 'values' / 'finance_colors.xml').write_text('''<?xml version="1.0" encoding="utf-8"?>
<resources>
    <color name="finance_teal">#0D9488</color>
</resources>
''', encoding='utf8')
print('android patched')
