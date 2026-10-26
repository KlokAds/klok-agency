import 'dart:async';
import 'dart:convert';
import 'dart:math';

import 'package:crypto/crypto.dart';
import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:http/http.dart' as http;
import 'package:local_auth/local_auth.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';

/// The agency's books, shown whole inside the app: every change on the site is in the app at once.
const site = 'https://billing.klokads.com';
// The site's colours (resources/css/app.css): the purple brand, its light tint for dark
// screens, the violet charcoal of the dark theme and the lavender paper of the light one.
const brand = Color(0xFF6F4A97);
const brandDeep = Color(0xFF5A3B82);
const brandLight = Color(0xFFC2A3DF);
const rail = Color(0xFF0C0B13);
const paper = Color(0xFFF4F2F7);
const inkDark = Color(0xFFEEF2F6);
const lineDark = Color(0xFF27243A);
const lineLight = Color(0xFFE3DFEA);
const surfaceDark = Color(0xFF17151F);
const good = Color(0xFF16A34A);
const bad = Color(0xFFDC2626);

final notifications = FlutterLocalNotificationsPlugin();
const smsChannel = MethodChannel('klok/sms');
const updateChannel = MethodChannel('klok/update');
const linkChannel = MethodChannel('klok/link');

/// A page of our own site, or nothing: a link from a notification, an email or another app is
/// opened only when it is https and on our host, so no other page can sit inside the app's frame.
Uri? safeUrl(String url) {
  final full = url.startsWith('http') ? url : '$site${url.startsWith('/') ? '' : '/'}$url';
  final uri = Uri.tryParse(full);
  if (uri == null || uri.scheme != 'https' || uri.host != Uri.parse(site).host) {
    return null;
  }
  return uri;
}

/// Two builds of one app: the plain one for everyone, and (built with --dart-define=SMS=true)
/// the one that also forwards payment SMS, for the Owner's phone only. Play Protect blocks any
/// downloaded app that reads SMS, so only the phone that needs it carries it.
const smsBuild = bool.fromEnvironment('SMS');
const flavor = smsBuild ? 'sms' : 'plain';

/// Money that needs you now (a payment, credit run out, card low): pops up over everything, loud.
const urgent = AndroidNotificationChannel(
  'finance_urgent',
  'জরুরি — টাকা ও campaign',
  description: 'Payment এসেছে, credit শেষ, card-এ dollar কম — জোরে শব্দ করে উপরে আসে',
  importance: Importance.max,
  playSound: true,
  enableVibration: true,
);

/// Everything else: the usual notification sound.
const normal = AndroidNotificationChannel(
  'finance_normal',
  'সাধারণ খবর',
  description: 'Reminder, কাজ, সারসংক্ষেপ — সাধারণ শব্দে আসে',
  importance: Importance.high,
  playSound: true,
  enableVibration: true,
);

/// Where a tapped notification should open (set before the page is ready, read once it is).
final opened = StreamController<String>.broadcast();
String? openFirst;
int appBuild = 0;

Future<void> setUpNotifications() async {
  final android = notifications.resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>();
  await android?.createNotificationChannel(urgent);
  await android?.createNotificationChannel(normal);
  await notifications.initialize(
    const InitializationSettings(android: AndroidInitializationSettings('ic_notification')),
    onDidReceiveNotificationResponse: (response) => go(response.payload),
  );
}

/// In the open app Android shows nothing by itself, so the app shows it, on the same channel.
Future<void> showAlert(RemoteMessage message) async {
  final data = message.data;
  final title = message.notification?.title ?? data['title'] ?? 'KlokOne';
  final body = message.notification?.body ?? data['body'] ?? '';
  final loud = data['level'] == 'urgent';
  final channel = loud ? urgent : normal;

  await notifications.show(
    (data['tag'] ?? '$title$body').hashCode,
    title,
    body,
    NotificationDetails(
      android: AndroidNotificationDetails(
        channel.id,
        channel.name,
        importance: channel.importance,
        priority: loud ? Priority.max : Priority.high,
        icon: 'ic_notification',
        color: brand,
        styleInformation: BigTextStyleInformation(body),
        category: AndroidNotificationCategory.message,
        visibility: NotificationVisibility.private,
      ),
    ),
    payload: data['url'],
  );
}

String appVersion = '?';

/// Sends an error to the server's crash log (the app cannot be signed in when it fails).
Future<void> report(String where, Object error, StackTrace? stack) async {
  try {
    await http
        .post(
          Uri.parse('$site/hooks/app-crash'),
          headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
          body: jsonEncode({'where': where, 'error': '$error\n$stack'.substring(0, min(18000, '$error\n$stack'.length)), 'version': appVersion}),
        )
        .timeout(const Duration(seconds: 8));
  } catch (_) {}
}

/// One start-up step; when it fails the app still opens (the site works without it).
Future<void> step(String name, Future<void> Function() run) async {
  try {
    await run();
  } catch (error, stack) {
    unawaited(report('start:$name', error, stack));
  }
}

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  FlutterError.onError = (details) {
    FlutterError.presentError(details);
    report('flutter', details.exception, details.stack);
  };
  PlatformDispatcher.instance.onError = (error, stack) {
    report('dart', error, stack);
    return true;
  };
  SystemChrome.setSystemUIOverlayStyle(const SystemUiOverlayStyle(statusBarColor: rail, statusBarIconBrightness: Brightness.light));

  await step('version', () async {
    final info = await PackageInfo.fromPlatform();
    appBuild = int.tryParse(info.buildNumber) ?? 0;
    appVersion = '${info.version}+${info.buildNumber}';
  });
  await step('firebase', () async {
    await Firebase.initializeApp();
    FirebaseMessaging.onMessageOpenedApp.listen((message) => go(message.data['url']));
    FirebaseMessaging.onMessage.listen(showAlert);
    // Tapped while the app was closed: open that page once it starts.
    final first = await FirebaseMessaging.instance.getInitialMessage();
    openFirst = first?.data['url'];
  });
  await step('notifications', () async {
    await setUpNotifications();
    final launch = await notifications.getNotificationAppLaunchDetails();
    if (launch?.didNotificationLaunchApp == true) {
      openFirst ??= launch!.notificationResponse?.payload;
    }
  });

  // Opened from a billing.klokads.com link (an email's button): that page, then any later ones.
  await step('links', () async {
    final link = await linkChannel.invokeMethod<String>('initial');
    if (link != null && link.isNotEmpty) {
      openFirst = link;
    }
    linkChannel.setMethodCallHandler((call) async {
      if (call.method == 'open' && call.arguments is String) {
        opened.add(call.arguments as String);
      }
    });
  });

  // Payment SMS that waited for internet go now.
  unawaited(smsChannel.invokeMethod('flush').catchError((_) => null));

  runApp(const FinanceApp());
}

/// The site's own light or dark, for the app's pages too.
final darkMode = ValueNotifier<bool>(false);

void go(String? url) {
  if (url != null && url.isNotEmpty) {
    opened.add(url);
  }
}

class FinanceApp extends StatelessWidget {
  const FinanceApp({super.key});

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<bool>(
      valueListenable: darkMode,
      builder: (context, dark, _) => MaterialApp(
        title: 'KlokOne',
        debugShowCheckedModeBanner: false,
        theme: appTheme(dark),
        // Over every screen (the site and the app's own settings alike).
        builder: (context, child) => LockGate(child: child ?? const SizedBox()),
        home: const SitePage(),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// App lock: the fingerprint (with the phone's own PIN or pattern behind it), the
// app's own PIN, or nothing. Chosen in the app's settings; changing it asks first.
// ---------------------------------------------------------------------------

class Lock {
  static const modes = {'bio': 'আঙুলের ছাপ / phone-এর lock', 'pin': 'App-এর নিজের PIN', 'off': 'বন্ধ'};
  static const afters = {0: 'সাথে সাথে', 1: '১ মিনিট পর', 5: '৫ মিনিট পর', 15: '১৫ মিনিট পর'};

  static Future<SharedPreferences> get _p => SharedPreferences.getInstance();

  static Future<String> mode() async => (await _p).getString('lock.mode') ?? 'off';
  static Future<int> after() async => (await _p).getInt('lock.after') ?? 1;
  static Future<bool> pinWithFinger() async => (await _p).getBool('lock.pin_finger') ?? false;

  /// New PINs are this long, and go in by themselves at the last digit.
  static const pinDigits = 6;

  /// The kept PIN's length; 0 for one set before the length was kept (that one ends with ✓).
  static Future<int> pinLength() async => (await _p).getInt('lock.pin_len') ?? 0;

  /// The first PINs were one SHA-256; now PBKDF2-HMAC-SHA256, so a copied prefs file does not
  /// give the PIN away in a second.
  static const _rounds = 60000;

  static String _hash(String salt, String pin) => sha256.convert(utf8.encode('$salt:$pin')).toString();

  static String _stretch(String salt, String pin) {
    final mac = Hmac(sha256, utf8.encode(pin));
    var block = mac.convert([...utf8.encode(salt), 0, 0, 0, 1]).bytes;
    final out = List<int>.from(block);
    for (var i = 1; i < _rounds; i++) {
      block = mac.convert(block).bytes;
      for (var j = 0; j < out.length; j++) {
        out[j] ^= block[j];
      }
    }
    return base64Url.encode(out);
  }

  static Future<void> setPin(String pin) async {
    final p = await _p;
    final salt = base64Url.encode(List<int>.generate(16, (_) => Random.secure().nextInt(256)));
    await p.setString('lock.salt', salt);
    await p.setString('lock.pin', _stretch(salt, pin));
    await p.setInt('lock.pin_len', pin.length);
    await p.setInt('lock.pin_v', 2);
    await p.remove('lock.wrong');
    await p.remove('lock.wait_until');
  }

  /// Wrong PINs are counted on disk: five in a row and the pad waits, twice as long each round.
  static Future<DateTime?> waitUntil() async {
    final at = (await _p).getInt('lock.wait_until');
    return at == null || at < DateTime.now().millisecondsSinceEpoch ? null : DateTime.fromMillisecondsSinceEpoch(at);
  }

  static Future<bool> checkPin(String pin) async {
    final p = await _p;
    if (await waitUntil() != null) {
      return false;
    }
    final salt = p.getString('lock.salt');
    final hash = p.getString('lock.pin');
    if (salt == null || hash == null) {
      return false;
    }
    final stretched = (p.getInt('lock.pin_v') ?? 1) >= 2;
    final ok = stretched ? _stretch(salt, pin) == hash : _hash(salt, pin) == hash;
    if (ok) {
      await p.remove('lock.wrong');
      await p.remove('lock.wait_until');
      if (!stretched) {
        await setPin(pin); // the old hash is replaced by the stretched one
      }
      return true;
    }
    final wrong = (p.getInt('lock.wrong') ?? 0) + 1;
    final rounds = (p.getInt('lock.wait_rounds') ?? 0);
    await p.setInt('lock.wrong', wrong);
    if (wrong >= 5) {
      final seconds = 30 * (1 << rounds.clamp(0, 6));
      await p.setInt('lock.wait_until', DateTime.now().add(Duration(seconds: seconds)).millisecondsSinceEpoch);
      await p.setInt('lock.wait_rounds', rounds + 1);
      await p.setInt('lock.wrong', 0);
    }
    return false;
  }

  static Future<bool> hasPin() async => (await _p).getString('lock.pin') != null;

  /// The phone's own PIN / pattern screen, for when the finger is not read.
  static Future<bool> phonePin(String reason) async {
    try {
      return await const MethodChannel('klok/lock').invokeMethod<bool>('phoneCredential', {'reason': reason}) ?? false;
    } catch (_) {
      return false;
    }
  }

  /// Before the fingerprint is switched on: it must really be there and really pass.
  /// Unlike [finger], a phone with no fingerprint set up says no, with why.
  static Future<String?> fingerOk(String reason) async {
    final auth = LocalAuthentication();
    try {
      if (!await auth.isDeviceSupported() || !await auth.canCheckBiometrics || (await auth.getAvailableBiometrics()).isEmpty) {
        return 'এই phone-এ আঙুলের ছাপ চালু নেই — phone-এর Settings থেকে আগে fingerprint যোগ করুন।';
      }
      final ok = await auth.authenticate(localizedReason: reason, options: const AuthenticationOptions(stickyAuth: true, biometricOnly: true));
      return ok ? null : 'আঙুলের ছাপ মেলেনি — চালু করা হয়নি।';
    } on PlatformException {
      return 'আঙুলের ছাপ নেওয়া গেল না — চালু করা হয়নি।';
    }
  }

  /// The fingerprint, or the phone's PIN/pattern. A phone with no screen lock passes.
  static Future<bool> finger(String reason) async {
    final auth = LocalAuthentication();
    try {
      if (!await auth.isDeviceSupported()) {
        return true;
      }
      return await auth.authenticate(localizedReason: reason, options: const AuthenticationOptions(stickyAuth: true));
    } on PlatformException catch (e) {
      return e.code == 'NotAvailable' || e.code == 'PasscodeNotSet' || e.code == 'NotEnrolled';
    }
  }
}

/// Asks for whatever the lock is (to open the app, or before changing the lock itself).
Future<bool> confirmIdentity(BuildContext context, {String reason = 'KlokOne খুলতে'}) async {
  final mode = await Lock.mode();
  if (mode == 'off') {
    return true;
  }
  if (mode == 'bio') {
    // The finger, or the phone's own PIN / pattern when the finger is not read.
    return await Lock.finger(reason) || await Lock.phonePin(reason);
  }
  if (!context.mounted) {
    return false;
  }
  final ok = await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => PinPage(title: reason)));
  return ok ?? false;
}

class LockGate extends StatefulWidget {
  const LockGate({super.key, required this.child});

  final Widget child;

  @override
  State<LockGate> createState() => _LockGateState();
}

class _LockGateState extends State<LockGate> with WidgetsBindingObserver {
  bool locked = true;
  bool asking = false;
  // Not known yet: a plain screen, no fingerprint button, no keypad.
  String? mode;
  bool pinFinger = false;
  int pinLength = 0;
  DateTime? leftAt;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    unlock();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) async {
    // The fingerprint prompt itself sends the app to the background for a moment.
    if (asking) {
      return;
    }
    if (state == AppLifecycleState.paused) {
      leftAt = DateTime.now();
    } else if (state == AppLifecycleState.resumed && leftAt != null) {
      final away = DateTime.now().difference(leftAt!);
      leftAt = null;
      if ((await Lock.mode()) != 'off' && away >= Duration(minutes: await Lock.after())) {
        setState(() => locked = true);
        unlock();
      }
      unawaited(smsChannel.invokeMethod('flush').catchError((_) => null));
    }
  }

  Future<void> unlock() async {
    if (asking) {
      return;
    }
    asking = true;
    final current = await Lock.mode();
    pinFinger = await Lock.pinWithFinger();
    pinLength = await Lock.pinLength();
    if (mounted) {
      setState(() => mode = current);
    }
    var ok = current == 'off';
    try {
      if (current == 'bio') {
        ok = await Lock.finger('KlokOne খুলতে আঙুলের ছাপ দিন');
      }
    } catch (error, stack) {
      unawaited(report('lock', error, stack));
    } finally {
      asking = false;
    }
    if (mounted) {
      setState(() {
        locked = !ok;
      });
    }
  }

  /// The fingerprint, tapped on the PIN screen (when switched on in the settings).
  Future<void> fingerOnPin() async {
    if (asking) {
      return;
    }
    asking = true;
    try {
      // A real fingerprint only: a phone with none set up does not open the app this way.
      if (await Lock.fingerOk('KlokOne খুলতে আঙুলের ছাপ দিন') == null && mounted) {
        setState(() => locked = false);
      }
    } finally {
      asking = false;
    }
  }

  /// The phone's own PIN / pattern, when the fingerprint does not work.
  Future<void> phonePin() async {
    if (asking) {
      return;
    }
    asking = true;
    try {
      if (await Lock.phonePin('KlokOne খুলতে phone-এর PIN / pattern দিন') && mounted) {
        setState(() => locked = false);
      }
    } finally {
      asking = false;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        widget.child,
        if (locked)
          Positioned.fill(
            child: Material(
              color: rail,
              child: mode == null
                ? const SizedBox.expand()
                : mode == 'pin'
                ? PinPad(
                    title: 'PIN দিয়ে খুলুন',
                    length: pinLength,
                    onFinger: pinFinger ? fingerOnPin : null,
                    onDone: (pin) async {
                      final ok = await Lock.checkPin(pin);
                      if (ok && mounted) {
                        setState(() => locked = false);
                      }
                      return ok;
                    },
                  )
                : ColoredBox(
                    color: rail,
                    child: SafeArea(
                      child: Center(
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Icon(Icons.fingerprint, size: 76, color: brandLight),
                            const SizedBox(height: 18),
                            const Text('KlokOne', style: TextStyle(fontSize: 22, fontWeight: FontWeight.w700, color: Colors.white)),
                            const SizedBox(height: 28),
                            FilledButton.icon(
                              style: FilledButton.styleFrom(backgroundColor: brand),
                              onPressed: unlock,
                              icon: const Icon(Icons.lock_open),
                              label: const Text('আঙুলের ছাপ দিয়ে খুলুন'),
                            ),
                            const SizedBox(height: 12),
                            TextButton.icon(
                              style: TextButton.styleFrom(foregroundColor: Colors.white70),
                              onPressed: phonePin,
                              icon: const Icon(Icons.dialpad),
                              label: const Text('Phone-এর PIN / pattern দিয়ে খুলুন'),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
            ),
          ),
      ],
    );
  }
}

/// The PIN keypad, laid out like the phone's own: 1–9, then 0 with the fingerprint (when allowed)
/// and delete beside it. A PIN of [length] digits goes in by itself at the last one; an old PIN
/// whose length was not kept (0) ends with ✓. Five wrong tries in a row wait thirty seconds.
class PinPad extends StatefulWidget {
  const PinPad({super.key, required this.title, required this.onDone, this.onFinger, this.subtitle, this.length = Lock.pinDigits});

  final String title;
  final String? subtitle;
  final int length;
  final Future<bool> Function(String pin) onDone;
  final VoidCallback? onFinger;

  @override
  State<PinPad> createState() => _PinPadState();
}

class _PinPadState extends State<PinPad> {
  String pin = '';
  String? error;
  int wrong = 0;
  bool busy = false;
  DateTime? waitUntil;

  static const letters = {'2': 'ABC', '3': 'DEF', '4': 'GHI', '5': 'JKL', '6': 'MNO', '7': 'PQRS', '8': 'TUV', '9': 'WXYZ'};

  int get dots => widget.length > 0 ? widget.length : 6;

  Future<void> submit() async {
    if (busy) {
      return;
    }
    busy = true;
    final ok = await widget.onDone(pin);
    busy = false;
    if (!mounted) {
      return;
    }
    if (ok) {
      wrong = 0;
      setState(() => pin = '');
      return;
    }
    wrong++;
    HapticFeedback.heavyImpact();
    waitUntil = await Lock.waitUntil();
    if (!mounted) {
      return;
    }
    setState(() {
      pin = '';
      error = waitUntil != null ? '${waitUntil!.difference(DateTime.now()).inSeconds + 1} সেকেন্ড অপেক্ষা করুন' : 'PIN মেলেনি';
    });
  }

  Future<void> press(String key) async {
    if (busy) {
      return;
    }
    if (waitUntil != null && DateTime.now().isBefore(waitUntil!)) {
      setState(() => error = '${waitUntil!.difference(DateTime.now()).inSeconds + 1} সেকেন্ড অপেক্ষা করুন');
      return;
    }
    HapticFeedback.selectionClick();
    if (key == '<') {
      setState(() => pin = pin.isEmpty ? '' : pin.substring(0, pin.length - 1));
      return;
    }
    if (key == 'ok') {
      if (pin.length < 4) {
        setState(() => error = 'কমপক্ষে ৪ সংখ্যা');
        return;
      }
      await submit();
      return;
    }
    if (pin.length >= dots) {
      return;
    }
    setState(() {
      pin += key;
      error = null;
    });
    // The last digit: in it goes, no ✓ to press.
    if (widget.length > 0 && pin.length == widget.length) {
      await Future<void>.delayed(const Duration(milliseconds: 120));
      await submit();
    }
  }

  Widget digit(String label) {
    return Padding(
      padding: const EdgeInsets.all(9),
      child: Material(
        color: const Color(0x14FFFFFF),
        shape: const CircleBorder(),
        child: InkWell(
          customBorder: const CircleBorder(),
          onTap: () => press(label),
          child: SizedBox(
            width: 74,
            height: 74,
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Text(label, style: const TextStyle(color: Colors.white, fontSize: 30, fontWeight: FontWeight.w400, height: 1.1)),
                Text(letters[label] ?? '', style: const TextStyle(color: Colors.white54, fontSize: 9.5, letterSpacing: 1.6)),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget side({IconData? icon, VoidCallback? onTap, String? tip}) {
    return Padding(
      padding: const EdgeInsets.all(9),
      child: SizedBox(
        width: 74,
        height: 74,
        child: icon == null
            ? null
            : IconButton(tooltip: tip, onPressed: onTap, icon: Icon(icon, color: Colors.white70, size: 28)),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return ColoredBox(
      color: rail,
      child: SafeArea(
        child: Column(
          children: [
            const Spacer(flex: 2),
            const Icon(Icons.lock_outline, color: brandLight, size: 38),
            const SizedBox(height: 14),
            Text(widget.title, textAlign: TextAlign.center, style: const TextStyle(color: Colors.white, fontSize: 19, fontWeight: FontWeight.w600)),
            if (widget.subtitle != null) ...[
              const SizedBox(height: 6),
              Text(widget.subtitle!, textAlign: TextAlign.center, style: const TextStyle(color: Colors.white60, fontSize: 13.5)),
            ],
            const SizedBox(height: 24),
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: List.generate(
                dots,
                (i) => AnimatedContainer(
                  duration: const Duration(milliseconds: 120),
                  margin: const EdgeInsets.symmetric(horizontal: 8),
                  width: 14,
                  height: 14,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: i < pin.length ? brandLight : Colors.transparent,
                    border: Border.all(color: i < pin.length ? brandLight : Colors.white38, width: 1.5),
                  ),
                ),
              ),
            ),
            SizedBox(height: 34, child: Center(child: Text(error ?? '', style: const TextStyle(color: Color(0xFFFCA5A5), fontSize: 13.5)))),
            const Spacer(),
            for (final row in [
              ['1', '2', '3'],
              ['4', '5', '6'],
              ['7', '8', '9'],
            ])
              Row(mainAxisAlignment: MainAxisAlignment.center, children: row.map(digit).toList()),
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                widget.onFinger != null
                    ? side(icon: Icons.fingerprint, onTap: widget.onFinger, tip: 'আঙুলের ছাপ')
                    : widget.length == 0
                        ? side(icon: Icons.check_rounded, onTap: () => press('ok'), tip: 'ঠিক আছে')
                        : side(),
                digit('0'),
                side(icon: Icons.backspace_outlined, onTap: () => press('<'), tip: 'মুছুন'),
              ],
            ),
            if (widget.onFinger != null && widget.length == 0)
              TextButton(onPressed: () => press('ok'), child: const Text('ঠিক আছে', style: TextStyle(color: Colors.white70))),
            const Spacer(),
          ],
        ),
      ),
    );
  }
}

/// A PIN check as its own screen (before changing the lock).
class PinPage extends StatelessWidget {
  const PinPage({super.key, required this.title});

  final String title;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: rail,
      body: FutureBuilder<int>(
        future: Lock.pinLength(),
        builder: (context, length) => !length.hasData
            ? const SizedBox.expand()
            : PinPad(
                title: 'PIN দিন',
                subtitle: title,
                length: length.data!,
                onDone: (pin) async {
                  final ok = await Lock.checkPin(pin);
                  if (ok && context.mounted) {
                    Navigator.of(context).pop(true);
                  }
                  return ok;
                },
              ),
      ),
    );
  }
}

/// A new 6-digit PIN, typed twice; each goes in at its last digit.
class NewPinPage extends StatefulWidget {
  const NewPinPage({super.key});

  @override
  State<NewPinPage> createState() => _NewPinPageState();
}

class _NewPinPageState extends State<NewPinPage> {
  String? first;
  bool mismatch = false;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: rail,
      body: PinPad(
        key: ValueKey(first),
        title: first == null ? 'নতুন ৬ সংখ্যার PIN দিন' : 'আবার একই PIN দিন',
        subtitle: mismatch ? 'দুটো মেলেনি — আবার শুরু করুন' : null,
        onDone: (pin) async {
          if (first == null) {
            setState(() {
              first = pin;
              mismatch = false;
            });
            return true;
          }
          if (pin != first) {
            setState(() {
              first = null;
              mismatch = true;
            });
            return true;
          }
          await Lock.setPin(pin);
          if (context.mounted) {
            Navigator.of(context).pop(true);
          }
          return true;
        },
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// The site.
// ---------------------------------------------------------------------------

class SitePage extends StatefulWidget {
  const SitePage({super.key});

  @override
  State<SitePage> createState() => _SitePageState();
}

class _SitePageState extends State<SitePage> {
  InAppWebViewController? web;
  PullToRefreshController? refresh;
  StreamSubscription<String>? openSub;
  String? token;
  String? handedOver;
  double progress = 0;
  bool failed = false;
  // The page's own background: the phone's bars above and below take it (dark page, dark bars).
  Color pageColor = paper;

  void setBars(List<dynamic> args) {
    final dark = args.isNotEmpty && args[0] == 'dark';
    darkMode.value = dark;
    var color = dark ? rail : paper;
    final match = RegExp(r'rgba?\((\d+),\s*(\d+),\s*(\d+)').firstMatch(args.length > 1 ? '${args[1]}' : '');
    if (match != null) {
      color = Color.fromARGB(255, int.parse(match[1]!), int.parse(match[2]!), int.parse(match[3]!));
    }
    final icons = dark ? Brightness.light : Brightness.dark;
    SystemChrome.setSystemUIOverlayStyle(SystemUiOverlayStyle(
      statusBarColor: color,
      statusBarIconBrightness: icons,
      systemNavigationBarColor: color,
      systemNavigationBarIconBrightness: icons,
    ));
    if (mounted && color != pageColor) {
      setState(() => pageColor = color);
    }
  }
  DateTime? checkedUpdate;
  bool installing = false;

  @override
  void initState() {
    super.initState();
    refresh = PullToRefreshController(
      settings: PullToRefreshSettings(color: brand),
      onRefresh: () async => web?.reload(),
    );
    openSub = opened.stream.listen(open);
    askPermissions();
    // The server says a new version is out: look now, not on the next hourly check.
    step('update-listen', () async {
      FirebaseMessaging.onMessage.listen((m) {
        if (m.data['kind'] == 'app_update') {
          checkUpdate(now: true);
        }
      });
      FirebaseMessaging.onMessageOpenedApp.listen((m) {
        if (m.data['kind'] == 'app_update') {
          checkUpdate(now: true);
        }
      });
    });
  }

  @override
  void dispose() {
    openSub?.cancel();
    super.dispose();
  }

  Future<void> askPermissions() async {
    await step('permission', () async {
      await Permission.notification.request();
      await FirebaseMessaging.instance.requestPermission(alert: true, badge: true, sound: true);
      token = await FirebaseMessaging.instance.getToken();
      FirebaseMessaging.instance.onTokenRefresh.listen((fresh) {
        token = fresh;
        handedOver = null;
        handOver();
      });
    });
    await handOver();
  }

  void open(String url) {
    final target = safeUrl(url);
    if (target != null) {
      web?.loadUrl(urlRequest: URLRequest(url: WebUri.uri(target)));
    }
  }

  Future<bool> signedIn() async {
    final url = await web?.getUrl();
    return url != null &&
        url.host == Uri.parse(site).host &&
        !url.path.startsWith('/login') &&
        !url.path.startsWith('/two-factor') &&
        !url.path.startsWith('/forgot-password');
  }

  /// A JSON request as the signed-in person (the page's own cookies and CSRF token).
  Future<({int status, dynamic body})?> request(String method, String path, [Map<String, dynamic>? body]) async {
    final controller = web;
    if (controller == null) {
      return null;
    }
    final result = await controller.callAsyncJavaScript(
      functionBody: '''
        const m = document.cookie.match(/(?:^|;\\s*)XSRF-TOKEN=([^;]+)/);
        const r = await fetch(path, {
          method,
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': m ? decodeURIComponent(m[1]) : '',
          },
          body: method === 'GET' ? undefined : JSON.stringify(body || {}),
        });
        let data = null;
        try { data = await r.json(); } catch (e) {}
        return { status: r.status, body: data };
      ''',
      arguments: {'path': path, 'method': method, 'body': body},
    );
    final value = result?.value;
    if (value is! Map) {
      return null;
    }
    return (status: (value['status'] as num?)?.toInt() ?? 0, body: value['body']);
  }

  /// Gives the server this phone's Firebase token under whoever is signed in, once per sign-in.
  Future<void> handOver() async {
    final current = token;
    if (current == null || handedOver == current) {
      return;
    }
    if (!await signedIn()) {
      // Signed out: whoever signs in next gets this phone's notifications.
      handedOver = null;
      return;
    }
    final result = await request('POST', '/app/device', {'token': current, 'version': appBuild});
    if (result?.status == 204) {
      handedOver = current;
    }
  }

  /// Makes this phone the payment SMS forwarder. Returns why not, or null when it is on.
  Future<String?> enableSms() async {
    final prefs = await SharedPreferences.getInstance();
    final status = await Permission.sms.request();
    if (!status.isGranted) {
      return 'SMS পড়ার অনুমতি দেওয়া হয়নি। Phone-এর Settings › Apps › KlokOne › Permissions থেকে দিন।';
    }
    final current = token ?? await FirebaseMessaging.instance.getToken();
    handedOver = null;
    await handOver();
    final result = await request('POST', '/app/sms-forwarder', {'token': current});
    final body = result?.body;
    if (result?.status == 403) {
      return 'শুধু Owner নিজের phone-এ এটা চালু করতে পারেন।';
    }
    if (result?.status != 200 || body is! Map || body['url'] is! String) {
      return 'চালু হলো না — নেট আছে আর login করা আছে কিনা দেখুন।';
    }
    await prefs.setString('sms.url', body['url'] as String);
    await prefs.setString('sms.senders', ((body['senders'] as List?) ?? []).join(','));
    await prefs.remove('sms.error');
    await prefs.remove('sms.off');
    // Kept awake by Android for SMS even when the battery saver would stop it.
    await Permission.ignoreBatteryOptimizations.request();
    // The hourly "still forwarding" starts now (and anything queued goes).
    unawaited(smsChannel.invokeMethod('flush').catchError((_) => null));
    return null;
  }

  /// Once, after the first sign-in: would they like the fingerprint lock? Yes (and a finger to
  /// prove it) turns it on; the sign-in stays either way, the lock only hides the app.
  Future<void> askLock() async {
    if (!await signedIn()) {
      return;
    }
    final prefs = await SharedPreferences.getInstance();
    if (prefs.getBool('lock.asked') == true || await Lock.mode() != 'off') {
      return;
    }
    await prefs.setBool('lock.asked', true);
    var can = false;
    try {
      final auth = LocalAuthentication();
      can = await auth.isDeviceSupported() && await auth.canCheckBiometrics;
    } catch (_) {}
    if (!can || !mounted) {
      return;
    }
    final yes = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        icon: const Icon(Icons.fingerprint, size: 40, color: brand),
        title: const Text('আঙুলের ছাপ দিয়ে app lock?'),
        content: const Text('চালু করলে app খোলার সময় আঙুলের ছাপ চাইবে। Login থেকে যাবে — আবার sign in করতে হবে না। পরে app-এর Settings থেকে বদলানো যায়।'),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: const Text('এখন না')),
          FilledButton(onPressed: () => Navigator.of(context).pop(true), child: const Text('চালু করুন')),
        ],
      ),
    );
    if (yes == true && await Lock.fingerOk('App lock চালু করতে আঙুলের ছাপ দিন') == null) {
      await prefs.setString('lock.mode', 'bio');
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('আঙুলের ছাপের lock চালু হয়েছে।')));
      }
    }
  }

  /// The SMS app on the Owner's phone: forwarding turns itself on at sign-in, once (unless it
  /// was turned off by hand). Anyone else signing in on it is left alone.
  Future<void> autoSms() async {
    if (!smsBuild || !await signedIn()) {
      return;
    }
    final prefs = await SharedPreferences.getInstance();
    if (prefs.getBool('sms.off') == true) {
      return;
    }
    final latest = await request('GET', '/app/latest?flavor=$flavor');
    final body = latest?.body;

    // Already forwarding: only bring the sender list up to date (a bank added in Settings
    // after forwarding was turned on would otherwise be filtered out here on the phone).
    if (prefs.getString('sms.url') != null && prefs.getString('sms.error') == null) {
      final senders = body is Map ? body['senders'] : null;
      if (senders is List && senders.isNotEmpty) {
        await prefs.setString('sms.senders', senders.join(','));
      }
      return;
    }

    if (body is Map && body['owner'] == true) {
      await step('sms', () async {
        final why = await enableSms();
        if (why == null && mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Payment SMS forward চালু হয়েছে — এই phone-এর bKash/Nagad/Rocket/bank SMS নিজে থেকে যাবে।')),
          );
        }
      });
    }
  }

  /// Once a day: is there a newer APK? Offered, never forced.
  /// Is there a newer APK? It is downloaded and installed here, by the app itself: the first time
  /// Android asks once, after that (Android 12+) it goes in by itself. No browser, no new sign-in.
  Future<void> checkUpdate({bool now = false}) async {
    if (!now && checkedUpdate != null && DateTime.now().difference(checkedUpdate!) < const Duration(hours: 1)) {
      return;
    }
    if (!await signedIn()) {
      return;
    }
    final result = await request('GET', '/app/latest?flavor=$flavor');
    final body = result?.body;
    if (body is! Map) {
      return;
    }
    checkedUpdate = DateTime.now();
    final version = (body['version'] as num?)?.toInt() ?? 0;
    final url = body['url'] as String?;
    if (version <= appBuild || url == null || !mounted || installing) {
      return;
    }

    final allowed = await updateChannel.invokeMethod<bool>('allowed').catchError((_) => false) ?? false;
    if (!allowed) {
      // Android asks once whether this app may install its own updates.
      final go = await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('App নিজে update হবে'),
          content: Text(
            'Version ${body['name'] ?? version} এসেছে। একবার অনুমতি দিন — এরপর নতুন version app নিজেই নামিয়ে বসাবে, login আর setting থেকে যাবে।',
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('পরে')),
            FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('অনুমতি দিন')),
          ],
        ),
      );
      if (go == true) {
        await updateChannel.invokeMethod('askToAllow');
        checkedUpdate = null; // asked again when the person comes back from Settings
      }
      return;
    }

    installing = true;
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('নতুন version ${body['name'] ?? version} নামানো হচ্ছে — শেষে app একবার বন্ধ হয়ে নতুনটা বসবে।')),
      );
    }
    await step('update', () async {
      final status = await updateChannel.invokeMethod<String>('install', {'url': url});
      if (status != 'started') {
        throw Exception('update $status');
      }
    });
    installing = false;
  }

  /// The site's "Sign out" in the app: this phone stops getting that person's notifications.
  Future<void> signingOut() async {
    final current = token;
    handedOver = null;
    if (current == null) {
      return;
    }
    try {
      await http
          .post(Uri.parse('$site/hooks/app-forget'), headers: {'Accept': 'application/json'}, body: {'token': current})
          .timeout(const Duration(seconds: 8));
    } catch (_) {
      // No internet: the server keeps sending until the token is replaced.
    }
  }

  void openSettings() {
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => SettingsPage(site: this)));
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) async {
        if (didPop) {
          return;
        }
        if (await web?.canGoBack() ?? false) {
          await web?.goBack();
        } else {
          await SystemNavigator.pop();
        }
      },
      child: Scaffold(
        backgroundColor: pageColor,
        body: SafeArea(
          child: Stack(
            children: [
              InAppWebView(
                // A notification's link may be a path or a full address (a client's invoice page).
                initialUrlRequest: URLRequest(
                  url: WebUri.uri(safeUrl(openFirst ?? '/dashboard') ?? Uri.parse('$site/dashboard')),
                ),
                pullToRefreshController: refresh,
                initialSettings: InAppWebViewSettings(
                  applicationNameForUserAgent: 'KlokFinanceApp/$appBuild',
                  javaScriptEnabled: true,
                  domStorageEnabled: true,
                  databaseEnabled: true,
                  thirdPartyCookiesEnabled: false,
                  allowFileAccess: false,
                  useShouldOverrideUrlLoading: true,
                  useOnDownloadStart: true,
                  supportZoom: false,
                  cacheMode: CacheMode.LOAD_DEFAULT,
                ),
                onWebViewCreated: (controller) {
                  web = controller;
                  // The app's own handlers answer only a page of our site, never one another app sent in.
                  Future<bool> ours() async => (await controller.getUrl())?.host == Uri.parse(site).host;
                  controller.addJavaScriptHandler(handlerName: 'appSettings', callback: (_) async {
                    if (await ours()) {
                      openSettings();
                    }
                  });
                  controller.addJavaScriptHandler(handlerName: 'theme', callback: (args) async {
                    if (await ours()) {
                      setBars(args);
                    }
                  });
                  controller.addJavaScriptHandler(handlerName: 'signingOut', callback: (_) async {
                    if (!await ours()) {
                      return false;
                    }
                    await signingOut();
                    return true;
                  });
                },
                shouldOverrideUrlLoading: (controller, action) async {
                  final uri = action.request.url;
                  if (uri == null) {
                    return NavigationActionPolicy.ALLOW;
                  }
                  // Phone calls, WhatsApp, Meta and other sites open in their own apps.
                  if (!['http', 'https'].contains(uri.scheme) || uri.host != Uri.parse(site).host) {
                    await launchUrl(uri, mode: LaunchMode.externalApplication);
                    return NavigationActionPolicy.CANCEL;
                  }
                  return NavigationActionPolicy.ALLOW;
                },
                onDownloadStartRequest: (controller, request) async {
                  await launchUrl(request.url, mode: LaunchMode.externalApplication);
                },
                onProgressChanged: (controller, value) {
                  setState(() => progress = value / 100);
                  if (value == 100) {
                    refresh?.endRefreshing();
                  }
                },
                onLoadStop: (controller, url) async {
                  refresh?.endRefreshing();
                  openFirst = null;
                  if (failed) {
                    setState(() => failed = false);
                  }
                  await handOver();
                  await autoSms();
                  await askLock();
                  await checkUpdate();
                },
                onReceivedError: (controller, request, error) {
                  refresh?.endRefreshing();
                  // Only the page itself failing (not a picture or a font) shows the offline screen.
                  if (request.isForMainFrame ?? true) {
                    setState(() => failed = true);
                  }
                },
              ),
              if (progress < 1) LinearProgressIndicator(value: progress, minHeight: 2, color: brand, backgroundColor: Colors.transparent),
              if (failed)
                Positioned.fill(
                  child: ColoredBox(
                    color: paper,
                    child: Center(
                      child: Padding(
                        padding: const EdgeInsets.all(28),
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Icon(Icons.wifi_off_rounded, size: 56, color: brand),
                            const SizedBox(height: 14),
                            const Text('নেট নেই', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w700)),
                            const SizedBox(height: 8),
                            const Text(
                              'এই পাতাটা আগে খোলা হয়নি, তাই phone-এ রাখা নেই। আগে খোলা পাতা আর quick entry নেট ছাড়াও চলে; payment SMS নেট এলে নিজে থেকে যাবে।',
                              textAlign: TextAlign.center,
                            ),
                            const SizedBox(height: 18),
                            FilledButton.icon(
                              style: FilledButton.styleFrom(backgroundColor: brand),
                              onPressed: () {
                                setState(() => failed = false);
                                web?.reload();
                              },
                              icon: const Icon(Icons.refresh),
                              label: const Text('আবার চেষ্টা'),
                            ),
                            TextButton(
                              onPressed: () {
                                setState(() => failed = false);
                                open('/dashboard');
                              },
                              child: const Text('Dashboard-এ যান'),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// The app's own settings: the lock, and (on the Owner's phone) SMS forwarding.
// ---------------------------------------------------------------------------

class SettingsPage extends StatefulWidget {
  const SettingsPage({super.key, required this.site});

  final _SitePageState site;

  @override
  State<SettingsPage> createState() => _SettingsPageState();
}

class _SettingsPageState extends State<SettingsPage> {
  String mode = 'off';
  int after = 1;
  bool pinFinger = false;
  bool smsOn = false;
  bool smsBusy = false;
  String? smsError;
  int pending = 0;
  DateTime? lastOk;
  DateTime? lastBeat;
  bool smsAllowed = false;
  bool batteryFree = false;
  String version = '';

  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.reload();
    final info = await PackageInfo.fromPlatform();
    final count = await smsChannel.invokeMethod<int>('pending').catchError((_) => 0);
    final ok = prefs.getInt('sms.last_ok');
    final beat = prefs.getInt('sms.last_beat');
    final allowed = smsBuild && await Permission.sms.isGranted;
    final battery = smsBuild && await Permission.ignoreBatteryOptimizations.isGranted;
    final revoked = prefs.getString('sms.error') == 'revoked';
    mode = await Lock.mode();
    after = await Lock.after();
    pinFinger = await Lock.pinWithFinger();
    if (!mounted) {
      return;
    }
    setState(() {
      smsOn = prefs.getString('sms.url') != null && !revoked;
      smsError = revoked ? 'অন্য phone-এ SMS forward চালু হয়েছে, বা server থেকে বন্ধ করা হয়েছে। আবার চালু করুন।' : null;
      pending = count ?? 0;
      lastOk = ok != null ? DateTime.fromMillisecondsSinceEpoch(ok) : null;
      lastBeat = beat != null ? DateTime.fromMillisecondsSinceEpoch(beat) : null;
      smsAllowed = allowed;
      batteryFree = battery;
      version = '${info.version} (${info.buildNumber})';
    });
  }

  Future<void> setMode(String value) async {
    if (value == mode || !await confirmIdentity(context, reason: 'App lock বদলাতে')) {
      return;
    }
    // The fingerprint goes on only once the finger has passed here and now.
    if (value == 'bio' && !await fingerAllowed()) {
      return;
    }
    if (value == 'pin' && mounted) {
      final made = await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => const NewPinPage()));
      if (made != true) {
        return;
      }
    }
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('lock.mode', value);
    setState(() => mode = value);
  }

  /// Asks for the finger; says why when it did not pass.
  Future<bool> fingerAllowed() async {
    final why = await Lock.fingerOk('আঙুলের ছাপে KlokOne খোলা চালু করতে');
    if (why != null && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(why)));
    }
    return why == null;
  }

  Future<void> changePin() async {
    if (!await confirmIdentity(context, reason: 'PIN বদলাতে') || !mounted) {
      return;
    }
    await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => const NewPinPage()));
  }

  Future<void> toggleSms(bool on) async {
    final prefs = await SharedPreferences.getInstance();
    if (!on) {
      await prefs.remove('sms.url');
      await prefs.remove('sms.senders');
      // Turned off by hand: it does not turn itself back on at the next sign-in.
      await prefs.setBool('sms.off', true);
      setState(() => smsOn = false);
      return;
    }
    setState(() {
      smsBusy = true;
      smsError = null;
    });
    try {
      smsError = await widget.site.enableSms();
      smsOn = smsError == null;
    } finally {
      if (mounted) {
        setState(() => smsBusy = false);
      }
    }
  }

  /// One thing the forwarding needs: ✓ when it is right, else a button that puts it right.
  Widget health(bool ok, IconData icon, String title, String subtitle, Future<void> Function() onFix, {String fix = 'ঠিক করুন'}) {
    return SettingsRow(
      icon: icon,
      tint: ok ? good : bad,
      title: title,
      subtitle: subtitle,
      trailing: ok && fix == 'ঠিক করুন'
          ? statusPill(true, 'ঠিক আছে')
          : FilledButton(
              onPressed: onFix,
              style: FilledButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 14), minimumSize: const Size(0, 38)),
              child: Text(fix),
            ),
    );
  }

  String ago(DateTime at) {
    final d = DateTime.now().difference(at);
    if (d.inMinutes < 1) return 'এইমাত্র';
    if (d.inHours < 1) return '${d.inMinutes} মিনিট আগে';
    if (d.inDays < 1) return '${d.inHours} ঘণ্টা আগে';
    return '${d.inDays} দিন আগে';
  }

  static const _modeIcons = {'bio': Icons.fingerprint, 'pin': Icons.dialpad_rounded, 'off': Icons.lock_open_rounded};
  static const _modeHints = {
    'bio': 'আঙুল না চিনলে phone-এর PIN/pattern',
    'pin': '৬ সংখ্যার, শুধু এই app-এর',
    'off': 'যে phone হাতে পাবে সে-ই খুলতে পারবে',
  };

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    return Scaffold(
      appBar: AppBar(title: Text(smsBuild ? 'App lock ও SMS' : 'App lock')),
      body: ListView(
        padding: const EdgeInsets.only(bottom: 28),
        children: [
          SettingsCard(
            title: 'App lock',
            icon: Icons.lock_outline,
            children: [
              for (final (i, entry) in Lock.modes.entries.indexed)
                SettingsRow(
                  first: i == 0,
                  icon: _modeIcons[entry.key] ?? Icons.lock_outline,
                  title: entry.value,
                  subtitle: _modeHints[entry.key],
                  selected: mode == entry.key,
                  tint: entry.key == 'off' ? bad : null,
                  onTap: () => setMode(entry.key),
                  trailing: mode == entry.key
                      ? Icon(Icons.check_circle_rounded, color: dark ? brandLight : brand)
                      : Icon(Icons.circle_outlined, color: dark ? const Color(0xFF363251) : const Color(0xFFCDC5D9)),
                ),
            ],
          ),
          if (mode == 'pin')
            SettingsCard(
              title: 'PIN',
              icon: Icons.password,
              children: [
                SettingsRow(
                  first: true,
                  icon: Icons.fingerprint,
                  title: 'আঙুলের ছাপেও খুলবে',
                  subtitle: 'PIN-এর বদলে আঙুল দিয়েও',
                  trailing: Switch(
                    value: pinFinger,
                    onChanged: (v) async {
                      // On: the finger must pass. Off: the PIN, so no one else switches it.
                      if (v ? !await fingerAllowed() : !await confirmIdentity(context, reason: 'আঙুলের ছাপ বন্ধ করতে')) {
                        return;
                      }
                      final prefs = await SharedPreferences.getInstance();
                      await prefs.setBool('lock.pin_finger', v);
                      setState(() => pinFinger = v);
                    },
                  ),
                ),
                SettingsRow(
                  icon: Icons.password,
                  title: 'PIN বদলান',
                  subtitle: 'আগে এখনকার PIN চাইবে',
                  trailing: const Icon(Icons.chevron_right_rounded),
                  onTap: changePin,
                ),
              ],
            ),
          if (mode != 'off')
            SettingsCard(
              title: 'আবার lock',
              icon: Icons.timer_outlined,
              children: [
                Padding(
                  padding: const EdgeInsets.all(14),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'App থেকে বের হওয়ার কতক্ষণ পর আবার খুলতে lock লাগবে',
                        style: TextStyle(fontSize: 13, color: dark ? const Color(0xFF9AA3B0) : const Color(0xFF5B6560)),
                      ),
                      const SizedBox(height: 12),
                      Wrap(
                        spacing: 8,
                        runSpacing: 8,
                        children: [
                          for (final e in Lock.afters.entries)
                            ChoicePill(
                              label: e.value,
                              on: after == e.key,
                              onTap: () async {
                                final prefs = await SharedPreferences.getInstance();
                                await prefs.setInt('lock.after', e.key);
                                setState(() => after = e.key);
                              },
                            ),
                        ],
                      ),
                    ],
                  ),
                ),
              ],
            ),
          if (smsBuild) ...[
            SettingsCard(
              title: 'Payment SMS',
              icon: Icons.sms_outlined,
              children: [
                SettingsRow(
                  first: true,
                  icon: Icons.forward_to_inbox_rounded,
                  title: 'এই phone থেকে payment SMS পাঠাও',
                  subtitle: 'Wallet-এর SIM যে phone-এ, শুধু সেখানে (Owner)। bKash, NAGAD, 16216 আর bank-এর SMS ছাড়া কিছুই phone থেকে যায় না।',
                  trailing: Switch(value: smsOn, onChanged: smsBusy ? null : toggleSms),
                ),
                if (smsBusy) const LinearProgressIndicator(color: brand, minHeight: 2),
                if (smsError != null)
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.fromLTRB(14, 10, 14, 10),
                    color: bad.withAlpha(24),
                    child: Text(smsError!, style: const TextStyle(color: bad, fontSize: 13.5, fontWeight: FontWeight.w600)),
                  ),
                if (smsOn) ...[
                  // The phone's own state: each thing that must be on, and a way to put it right.
                  health(
                    smsAllowed,
                    Icons.sms_outlined,
                    'SMS পড়ার অনুমতি',
                    smsAllowed ? 'দেওয়া আছে' : 'নেই — payment SMS ধরা যাবে না',
                    () async {
                      final status = await Permission.sms.request();
                      if (status.isPermanentlyDenied || status.isRestricted) {
                        await openAppSettings();
                      }
                      await load();
                    },
                  ),
                  health(
                    batteryFree,
                    Icons.battery_charging_full_rounded,
                    'Battery saver থেকে বাদ',
                    batteryFree ? 'হ্যাঁ — app বন্ধ থাকলেও SMS যাবে' : 'না — phone app থামিয়ে দিতে পারে',
                    () async {
                      await Permission.ignoreBatteryOptimizations.request();
                      await load();
                    },
                  ),
                  health(
                    pending == 0,
                    Icons.cloud_upload_outlined,
                    pending > 0 ? '$pending টা SMS নেট-এর অপেক্ষায়' : 'সব SMS পৌঁছেছে',
                    [
                      if (lastOk != null) 'শেষ SMS ${ago(lastOk!)}',
                      if (lastBeat != null) 'server-এ শেষ খবর ${ago(lastBeat!)}',
                      if (lastOk == null && lastBeat == null) 'এখনো কিছু যায়নি',
                    ].join(' · '),
                    () async {
                      await smsChannel.invokeMethod('flush');
                      await smsChannel.invokeMethod('beat').catchError((_) => false);
                      await Future<void>.delayed(const Duration(seconds: 2));
                      await load();
                    },
                    fix: 'এখনই পাঠাও',
                  ),
                  SettingsRow(
                    icon: Icons.forum_rounded,
                    selected: true,
                    title: 'SMS Center',
                    subtitle: 'সব payment SMS, কোন invoice-এ মিলেছে, sender, বাদ পড়া SMS খোঁজা',
                    trailing: const Icon(Icons.chevron_right_rounded),
                    onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => SmsCenterPage(site: widget.site))).then((_) => load()),
                  ),
                ],
              ],
            ),
          ],
          SettingsCard(
            title: 'App',
            icon: Icons.info_outline,
            children: [
              SettingsRow(
                first: true,
                icon: Icons.system_update_rounded,
                title: 'Version ${smsBuild ? '$version · SMS' : version}',
                subtitle: 'চাপলে নতুন version আছে কিনা দেখবে',
                trailing: const Icon(Icons.refresh_rounded),
                onTap: () {
                  widget.site.checkedUpdate = null;
                  widget.site.checkUpdate();
                },
              ),
            ],
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Payment SMS on this phone: what was sent and what the server made of it, the
// words that keep an SMS on the phone, and the senders it listens to.
// ---------------------------------------------------------------------------

/// The app's own pages follow the site: dark when the site is dark.
ThemeData appTheme(bool dark) {
  final scheme = ColorScheme.fromSeed(
    seedColor: brand,
    brightness: dark ? Brightness.dark : Brightness.light,
    primary: dark ? brandLight : brand,
    surface: dark ? surfaceDark : Colors.white,
  );
  return ThemeData(
    colorScheme: scheme,
    scaffoldBackgroundColor: dark ? rail : paper,
    useMaterial3: true,
    appBarTheme: AppBarTheme(
      backgroundColor: dark ? rail : paper,
      foregroundColor: dark ? inkDark : const Color(0xFF15211F),
      elevation: 0,
      scrolledUnderElevation: 0,
      centerTitle: false,
      titleTextStyle: TextStyle(fontSize: 20, fontWeight: FontWeight.w700, color: dark ? inkDark : const Color(0xFF15211F)),
    ),
    switchTheme: SwitchThemeData(
      thumbColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? Colors.white : null),
      trackColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? brand : null),
      trackOutlineColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? brand : null),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        backgroundColor: brand,
        foregroundColor: Colors.white,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(11)),
        textStyle: const TextStyle(fontWeight: FontWeight.w700),
      ),
    ),
    snackBarTheme: const SnackBarThemeData(behavior: SnackBarBehavior.floating),
    dividerTheme: DividerThemeData(color: dark ? lineDark : lineLight),
  );
}

/// A settings group: a small purple heading over a rounded card, as the site's panels.
class SettingsCard extends StatelessWidget {
  const SettingsCard({super.key, required this.title, required this.icon, required this.children});

  final String title;
  final IconData icon;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 18, 16, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.only(left: 2, bottom: 8),
            child: Row(
              children: [
                Container(width: 18, height: 3, decoration: BoxDecoration(color: brand, borderRadius: BorderRadius.circular(3))),
                const SizedBox(width: 8),
                Text(
                  title.toUpperCase(),
                  style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w800, letterSpacing: 1.2, color: dark ? brandLight : brandDeep),
                ),
              ],
            ),
          ),
          Container(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                colors: dark ? const [Color(0xFF1A1828), Color(0xFF14131E)] : const [Colors.white, Color(0xFFFCFBFE)],
              ),
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: dark ? lineDark : lineLight),
              boxShadow: dark ? null : const [BoxShadow(color: Color(0x14000000), blurRadius: 24, offset: Offset(0, 10), spreadRadius: -16)],
            ),
            clipBehavior: Clip.antiAlias,
            child: Column(children: children),
          ),
        ],
      ),
    );
  }
}

/// One row in a settings card: an icon in a tinted square, the words, and what sits at the end.
class SettingsRow extends StatelessWidget {
  const SettingsRow({super.key, required this.icon, required this.title, this.subtitle, this.trailing, this.onTap, this.tint, this.selected = false, this.first = false});

  final IconData icon;
  final String title;
  final String? subtitle;
  final Widget? trailing;
  final VoidCallback? onTap;
  final Color? tint;
  final bool selected;
  final bool first;

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    final color = tint ?? (dark ? brandLight : brand);
    return Material(
      color: selected ? (dark ? const Color(0xFF2B1D3F) : const Color(0xFFEFE6F8)) : Colors.transparent,
      child: InkWell(
        onTap: onTap,
        child: Container(
          decoration: BoxDecoration(border: first ? null : Border(top: BorderSide(color: dark ? lineDark : lineLight))),
          padding: const EdgeInsets.fromLTRB(14, 13, 14, 13),
          child: Row(
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: selected ? brand : color.withAlpha(dark ? 40 : 28),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Icon(icon, size: 20, color: selected ? Colors.white : color),
              ),
              const SizedBox(width: 13),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(title, style: TextStyle(fontSize: 15.5, fontWeight: FontWeight.w700, color: dark ? inkDark : const Color(0xFF15211F))),
                    if (subtitle != null) ...[
                      const SizedBox(height: 2),
                      Text(subtitle!, style: TextStyle(fontSize: 13, height: 1.35, color: dark ? const Color(0xFF9AA3B0) : const Color(0xFF5B6560))),
                    ],
                  ],
                ),
              ),
              if (trailing != null) ...[const SizedBox(width: 10), trailing!],
            ],
          ),
        ),
      ),
    );
  }
}

/// A small choice pill: the chosen one in the brand gradient, the others quiet.
class ChoicePill extends StatelessWidget {
  const ChoicePill({super.key, required this.label, required this.on, required this.onTap});

  final String label;
  final bool on;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    return GestureDetector(
      onTap: onTap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 150),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
        decoration: BoxDecoration(
          gradient: on ? const LinearGradient(colors: [Color(0xFF8D6BB3), brandDeep]) : null,
          color: on ? null : (dark ? const Color(0xFF1B1927) : const Color(0xFFF7F5FA)),
          borderRadius: BorderRadius.circular(11),
          border: Border.all(color: on ? Colors.transparent : (dark ? const Color(0xFF363251) : const Color(0xFFCDC5D9))),
          boxShadow: on ? const [BoxShadow(color: Color(0x667E5C9B), blurRadius: 14, offset: Offset(0, 6), spreadRadius: -6)] : null,
        ),
        child: Text(
          label,
          style: TextStyle(fontSize: 13.5, fontWeight: FontWeight.w700, color: on ? Colors.white : (dark ? const Color(0xFFB3BCC8) : const Color(0xFF4A5653))),
        ),
      ),
    );
  }
}

/// ✓ or ! at the end of a row, as a coloured pill.
Widget statusPill(bool ok, String label) {
  final color = ok ? good : bad;
  return Container(
    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
    decoration: BoxDecoration(color: color.withAlpha(30), borderRadius: BorderRadius.circular(999)),
    child: Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(ok ? Icons.check_rounded : Icons.priority_high_rounded, size: 14, color: color),
        const SizedBox(width: 4),
        Text(label, style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700, color: color)),
      ],
    ),
  );
}

/// One payment SMS as the SMS Center shows it: from the server (what it made of it) or from
/// this phone's own log (still waiting, stuck, or kept on the phone).
class SmsItem {
  SmsItem({
    required this.key,
    required this.status,
    required this.sender,
    required this.at,
    this.amount,
    this.body = '',
    this.trx,
    this.payer,
    this.invoice,
    this.invoiceUrl,
    this.client,
    this.error,
    this.localId,
  });

  final String key;
  final String status; // matched · unmatched · refund · pending · failed · skipped · synced
  final String sender;
  final int at;
  final int? amount;
  final String body;
  final String? trx;
  final String? payer;
  final String? invoice;
  final String? invoiceUrl;
  final String? client;
  final String? error;
  final String? localId;

  bool get waiting => status == 'pending' || status == 'failed';
}

const _months = ['জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর'];
const _weekdays = ['সোমবার', 'মঙ্গলবার', 'বুধবার', 'বৃহস্পতিবার', 'শুক্রবার', 'শনিবার', 'রবিবার'];

String taka(int poisha) {
  final whole = (poisha / 100).round().toString();
  final out = StringBuffer();
  for (var i = 0; i < whole.length; i++) {
    if (i > 0 && (whole.length - i) % 3 == 0) out.write(',');
    out.write(whole[i]);
  }
  return '৳$out';
}

String dayTitle(DateTime d) {
  final today = DateTime.now();
  final same = (DateTime a, DateTime b) => a.year == b.year && a.month == b.month && a.day == b.day;
  if (same(d, today)) return 'আজ · ${d.day} ${_months[d.month - 1]}';
  if (same(d, today.subtract(const Duration(days: 1)))) return 'গতকাল · ${d.day} ${_months[d.month - 1]}';
  return '${_weekdays[d.weekday - 1]}, ${d.day} ${_months[d.month - 1]}';
}

String clock(DateTime d) {
  final h = d.hour % 12 == 0 ? 12 : d.hour % 12;
  return '$h:${d.minute.toString().padLeft(2, '0')} ${d.hour < 12 ? 'AM' : 'PM'}';
}

String agoText(DateTime at) {
  final d = DateTime.now().difference(at);
  if (d.inMinutes < 1) return 'এইমাত্র';
  if (d.inHours < 1) return '${d.inMinutes} মিনিট আগে';
  if (d.inDays < 1) return '${d.inHours} ঘণ্টা আগে';
  return '${d.inDays} দিন আগে';
}

/// The Owner's SMS Center: what came in and what became of it, the senders, and the phone's
/// own state — all in one place, in three tabs.
class SmsCenterPage extends StatefulWidget {
  const SmsCenterPage({super.key, required this.site});

  final _SitePageState site;

  @override
  State<SmsCenterPage> createState() => _SmsCenterPageState();
}

class _SmsCenterPageState extends State<SmsCenterPage> {
  int tab = 0;
  bool loading = true;
  bool syncing = false;
  String? serverError;

  List<Map<String, dynamic>> history = [];
  List<Map<String, dynamic>> week = [];
  List<Map<String, dynamic>> senders = [];
  Map<String, dynamic> catalog = {};
  List<Map<String, dynamic>> log = [];
  Set<String> disabled = {};
  List<String> words = [];
  DateTime? lastSeen;
  DateTime? lastOk;
  int pending = 0;
  bool smsOn = false;
  bool smsAllowed = false;
  bool batteryFree = false;

  String filter = 'all';
  String search = '';
  Timer? timer;

  static const filters = {'all': 'সব', 'matched': 'মিলেছে', 'unmatched': 'মেলেনি', 'pending': 'অপেক্ষায়', 'failed': 'আটকে', 'refund': 'Refund'};

  @override
  void initState() {
    super.initState();
    load();
    // Fresh while open: a new SMS or a retry shows up by itself.
    timer = Timer.periodic(const Duration(seconds: 15), (_) => load(quiet: true));
  }

  @override
  void dispose() {
    timer?.cancel();
    super.dispose();
  }

  Future<void> load({bool quiet = false}) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.reload();
    final raw = await smsChannel.invokeMethod<String>('log').catchError((_) => '[]');
    final count = await smsChannel.invokeMethod<int>('pending').catchError((_) => 0);
    final allowed = await Permission.sms.isGranted;
    final battery = await Permission.ignoreBatteryOptimizations.isGranted;
    final savedWords = prefs.getString('sms.ignore') ?? await smsChannel.invokeMethod<String>('defaultIgnore').catchError((_) => '') ?? '';
    final result = await widget.site.request('GET', '/app/sms-center');
    final body = result?.body;
    if (!mounted) return;
    setState(() {
      loading = false;
      log = (jsonDecode(raw ?? '[]') as List).whereType<Map>().map((m) => m.cast<String, dynamic>()).toList();
      pending = count ?? 0;
      smsAllowed = allowed;
      batteryFree = battery;
      smsOn = prefs.getString('sms.url') != null && prefs.getString('sms.error') == null;
      disabled = (prefs.getString('sms.disabled') ?? '').split(',').where((s) => s.isNotEmpty).toSet();
      words = savedWords.split('\n').map((w) => w.trim()).where((w) => w.isNotEmpty).toList();
      final ok = prefs.getInt('sms.last_ok');
      lastOk = ok != null ? DateTime.fromMillisecondsSinceEpoch(ok) : null;
      if (result?.status == 200 && body is Map) {
        serverError = null;
        history = ((body['history'] as List?) ?? []).whereType<Map>().map((m) => m.cast<String, dynamic>()).toList();
        week = ((body['week'] as List?) ?? []).whereType<Map>().map((m) => m.cast<String, dynamic>()).toList();
        senders = ((body['senders'] as List?) ?? []).whereType<Map>().map((m) => m.cast<String, dynamic>()).toList();
        catalog = (body['catalog'] as Map?)?.cast<String, dynamic>() ?? {};
        final seen = body['last_seen'];
        lastSeen = seen is String ? DateTime.tryParse(seen)?.toLocal() : null;
      } else if (!quiet) {
        serverError = 'Server থেকে তালিকা আনা গেল না — নেট আর login দেখুন। Phone-এর নিজের তালিকা দেখানো হচ্ছে।';
      }
    });
  }

  /// Everything, newest first: the server's SMS, and this phone's ones still on their way.
  List<SmsItem> get items {
    final out = <SmsItem>[];
    final trxs = <String>{};
    for (final h in history) {
      final trx = h['trx_id'] as String?;
      if (trx != null) trxs.add(trx);
      out.add(SmsItem(
        key: 's${h['id']}',
        status: '${h['status']}',
        sender: '${h['sender'] ?? h['provider'] ?? ''}',
        at: (h['at'] as num?)?.toInt() ?? 0,
        amount: (h['amount'] as num?)?.toInt(),
        body: '${h['body'] ?? ''}',
        trx: trx,
        payer: h['payer'] as String?,
        invoice: h['invoice'] as String?,
        invoiceUrl: h['invoice_url'] as String?,
        client: h['client'] as String?,
      ));
    }
    for (final l in log) {
      final text = '${l['text'] ?? ''}';
      final status = '${l['status'] ?? 'pending'}';
      // A sent one the server has is already above (by its TrxID in the text).
      if (status == 'synced' && trxs.any((t) => text.contains(t))) continue;
      out.add(SmsItem(
        key: 'l${l['id']}',
        status: status,
        sender: '${l['from'] ?? ''}',
        at: (l['at'] as num?)?.toInt() ?? 0,
        amount: amountIn(text),
        body: status == 'skipped' ? 'OTP বা code-এর SMS ("${l['why']}") — phone থেকে বের হয়নি।' : text,
        invoice: l['invoice'] as String?,
        error: l['error'] as String?,
        localId: '${l['id']}',
      ));
    }
    out.sort((a, b) => b.at.compareTo(a.at));
    return out;
  }

  static int? amountIn(String text) {
    final m = RegExp(r'(?:Tk|BDT)\.?\s*([\d,]+(?:\.\d{1,2})?)', caseSensitive: false).firstMatch(text);
    if (m == null) return null;
    final value = double.tryParse(m.group(1)!.replaceAll(',', ''));
    return value == null ? null : (value * 100).round();
  }

  bool matchesFilter(SmsItem i) {
    final byStatus = switch (filter) {
      'all' => true,
      'failed' => i.status == 'failed' || i.status == 'skipped',
      'unmatched' => i.status == 'unmatched' || i.status == 'synced',
      _ => i.status == filter,
    };
    if (!byStatus) return false;
    if (search.isEmpty) return true;
    final q = search.toLowerCase();
    return [i.sender, i.body, i.trx ?? '', i.invoice ?? '', i.client ?? '', i.payer ?? ''].any((s) => s.toLowerCase().contains(q));
  }

  Future<void> sync() async {
    setState(() => syncing = true);
    final imported = await smsChannel.invokeMethod<int>('importInbox', {'days': 3}).catchError((_) => 0) ?? 0;
    await smsChannel.invokeMethod('flush').catchError((_) => null);
    await smsChannel.invokeMethod('beat').catchError((_) => false);
    await Future<void>.delayed(const Duration(seconds: 2));
    await load();
    if (!mounted) return;
    setState(() => syncing = false);
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(imported > 0 ? '$imported টা বাদ পড়া SMS খুঁজে পাঠানো হলো।' : 'সব ঠিক আছে — বাদ পড়া কোনো SMS নেই।'),
    ));
  }

  void openInvoice(String url) {
    Navigator.of(context).popUntil((r) => r.isFirst);
    widget.site.web?.loadUrl(urlRequest: URLRequest(url: WebUri('$site$url')));
  }

  // ------------------------------------------------------------------ dashboard

  Widget statTile(String label, String value, IconData icon, Color color, {String? sub, VoidCallback? onTap}) {
    final scheme = Theme.of(context).colorScheme;
    return Material(
      color: scheme.surfaceContainerHighest.withAlpha(120),
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(children: [
                Icon(icon, size: 18, color: color),
                const SizedBox(width: 6),
                Expanded(child: Text(label, style: TextStyle(fontSize: 12.5, color: scheme.onSurfaceVariant), overflow: TextOverflow.ellipsis)),
              ]),
              const SizedBox(height: 8),
              Text(value, style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w700)),
              if (sub != null) Text(sub, style: TextStyle(fontSize: 11.5, color: scheme.onSurfaceVariant)),
            ],
          ),
        ),
      ),
    );
  }

  Widget weekChart() {
    final scheme = Theme.of(context).colorScheme;
    final top = week.fold<int>(1, (m, d) => max(m, (d['amount'] as num?)?.toInt() ?? 0));
    final total = week.fold<int>(0, (s, d) => s + ((d['amount'] as num?)?.toInt() ?? 0));
    const short = ['সোম', 'মঙ্গল', 'বুধ', 'বৃহঃ', 'শুক্র', 'শনি', 'রবি'];
    return Card(
      elevation: 0,
      color: scheme.surfaceContainerHighest.withAlpha(120),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              const Expanded(child: Text('গত ৭ দিনে এসেছে', style: TextStyle(fontWeight: FontWeight.w700))),
              Text(taka(total), style: const TextStyle(fontWeight: FontWeight.w700, color: brand)),
            ]),
            const SizedBox(height: 14),
            SizedBox(
              height: 120,
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  for (final d in week)
                    Expanded(
                      child: Tooltip(
                        message: '${taka((d['amount'] as num?)?.toInt() ?? 0)} · ${d['count']} টা SMS',
                        triggerMode: TooltipTriggerMode.tap,
                        child: Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 5),
                          child: Column(
                            mainAxisAlignment: MainAxisAlignment.end,
                            children: [
                              Container(
                                height: max(4.0, 92 * (((d['amount'] as num?)?.toInt() ?? 0) / top)),
                                decoration: BoxDecoration(
                                  borderRadius: BorderRadius.circular(6),
                                  color: ((d['amount'] as num?)?.toInt() ?? 0) == 0 ? scheme.outlineVariant : brand.withAlpha(90),
                                ),
                                alignment: Alignment.bottomCenter,
                                child: FractionallySizedBox(
                                  heightFactor: ((d['amount'] as num?)?.toInt() ?? 0) == 0 ? 0.0 : (((d['matched'] as num?)?.toInt() ?? 0) / ((d['amount'] as num).toInt())).clamp(0.0, 1.0),
                                  child: Container(decoration: BoxDecoration(borderRadius: BorderRadius.circular(6), color: brand)),
                                ),
                              ),
                              const SizedBox(height: 6),
                              Text(
                                short[(DateTime.tryParse('${d['day']}') ?? DateTime.now()).weekday - 1],
                                style: TextStyle(fontSize: 10.5, color: scheme.onSurfaceVariant),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                ],
              ),
            ),
            const SizedBox(height: 8),
            Row(children: [
              Container(width: 10, height: 10, decoration: BoxDecoration(color: brand, borderRadius: BorderRadius.circular(3))),
              Text('  invoice-এ মিলেছে    ', style: TextStyle(fontSize: 11.5, color: scheme.onSurfaceVariant)),
              Container(width: 10, height: 10, decoration: BoxDecoration(color: brand.withAlpha(90), borderRadius: BorderRadius.circular(3))),
              Text('  বাকি', style: TextStyle(fontSize: 11.5, color: scheme.onSurfaceVariant)),
            ]),
          ],
        ),
      ),
    );
  }

  (String, Color) statusLook(String status) => switch (status) {
        'matched' => ('মিলেছে', const Color(0xFF16A34A)),
        'refund' => ('Refund', const Color(0xFF7C3AED)),
        'unmatched' || 'synced' => ('মেলেনি', const Color(0xFF0EA5E9)),
        'failed' => ('আটকে আছে', const Color(0xFFDC2626)),
        'skipped' => ('পাঠানো হয়নি', Colors.grey),
        _ => ('অপেক্ষায়', const Color(0xFFD97706)),
      };

  void details(SmsItem i) {
    final (label, color) = statusLook(i.status);
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(children: [
                Expanded(child: Text(i.sender, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700))),
                Text(label, style: TextStyle(color: color, fontWeight: FontWeight.w700)),
              ]),
              const SizedBox(height: 4),
              Text('${dayTitle(DateTime.fromMillisecondsSinceEpoch(i.at))} · ${clock(DateTime.fromMillisecondsSinceEpoch(i.at))}',
                  style: TextStyle(color: Theme.of(context).colorScheme.onSurfaceVariant)),
              const SizedBox(height: 14),
              SelectableText(i.body, style: const TextStyle(height: 1.45)),
              const SizedBox(height: 14),
              if (i.amount != null) Text('টাকা: ${taka(i.amount!)}'),
              if (i.payer != null) Text('যে নম্বর থেকে: ${i.payer}'),
              if (i.trx != null)
                Row(children: [
                  Expanded(child: Text('TrxID: ${i.trx}')),
                  TextButton.icon(
                    onPressed: () {
                      Clipboard.setData(ClipboardData(text: i.trx!));
                      Navigator.of(context).pop();
                    },
                    icon: const Icon(Icons.copy, size: 16),
                    label: const Text('Copy'),
                  ),
                ]),
              if (i.error != null && i.waiting) Text(i.error!, style: const TextStyle(color: Color(0xFFDC2626))),
              const SizedBox(height: 10),
              if (i.invoiceUrl != null)
                FilledButton.icon(
                  onPressed: () {
                    Navigator.of(context).pop();
                    openInvoice(i.invoiceUrl!);
                  },
                  icon: const Icon(Icons.receipt_long),
                  label: Text('Invoice ${i.invoice}${i.client != null ? ' · ${i.client}' : ''} খুলুন'),
                ),
              if (i.waiting && i.localId != null)
                OutlinedButton.icon(
                  onPressed: () async {
                    Navigator.of(context).pop();
                    await smsChannel.invokeMethod('retry', {'id': i.localId});
                    await Future<void>.delayed(const Duration(seconds: 2));
                    await load();
                  },
                  icon: const Icon(Icons.refresh),
                  label: const Text('আবার পাঠাও'),
                ),
            ],
          ),
        ),
      ),
    );
  }

  Widget smsCard(SmsItem i) {
    final scheme = Theme.of(context).colorScheme;
    final (label, color) = statusLook(i.status);
    return Material(
      color: scheme.surfaceContainerHighest.withAlpha(110),
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: () => details(i),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  CircleAvatar(
                    radius: 17,
                    backgroundColor: color.withAlpha(36),
                    child: Text(i.sender.isEmpty ? '?' : i.sender.substring(0, 1).toUpperCase(),
                        style: TextStyle(color: color, fontWeight: FontWeight.w800)),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(i.sender, style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700), overflow: TextOverflow.ellipsis),
                        Text(clock(DateTime.fromMillisecondsSinceEpoch(i.at)), style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant)),
                      ],
                    ),
                  ),
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      if (i.amount != null)
                        Text(taka(i.amount!), style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: i.status == 'refund' ? color : null)),
                      Container(
                        margin: const EdgeInsets.only(top: 3),
                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                        decoration: BoxDecoration(color: color.withAlpha(36), borderRadius: BorderRadius.circular(20)),
                        child: Text(label, style: TextStyle(color: color, fontSize: 11.5, fontWeight: FontWeight.w700)),
                      ),
                    ],
                  ),
                ],
              ),
              const SizedBox(height: 8),
              Text(i.body, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: scheme.onSurfaceVariant, height: 1.35, fontSize: 13)),
              if (i.invoice != null || (i.error != null && i.waiting) || (i.waiting && i.localId != null)) ...[
                const SizedBox(height: 8),
                Row(
                  children: [
                    if (i.invoice != null)
                      Flexible(child: ActionChip(
                        avatar: const Icon(Icons.receipt_long, size: 16),
                        label: Text('${i.invoice}${i.client != null ? ' · ${i.client}' : ''}', overflow: TextOverflow.ellipsis),
                        onPressed: i.invoiceUrl != null ? () => openInvoice(i.invoiceUrl!) : null,
                        visualDensity: VisualDensity.compact,
                      )),
                    if (i.error != null && i.waiting)
                      Expanded(child: Text(i.error!, style: const TextStyle(color: Color(0xFFDC2626), fontSize: 12))),
                    if (i.invoice == null && !(i.error != null && i.waiting)) const Spacer(),
                    if (i.waiting && i.localId != null)
                      TextButton.icon(
                        onPressed: () async {
                          await smsChannel.invokeMethod('retry', {'id': i.localId});
                          await Future<void>.delayed(const Duration(seconds: 2));
                          await load();
                        },
                        icon: const Icon(Icons.refresh, size: 18),
                        label: const Text('Retry'),
                      ),
                  ],
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget dashboard() {
    final scheme = Theme.of(context).colorScheme;
    final all = items;
    final now = DateTime.now();
    final today = all.where((i) {
      final d = DateTime.fromMillisecondsSinceEpoch(i.at);
      return d.year == now.year && d.month == now.month && d.day == now.day && i.status != 'refund' && i.status != 'skipped';
    }).toList();
    final shown = all.where(matchesFilter).toList();
    final live = smsOn && smsAllowed && lastSeen != null && DateTime.now().difference(lastSeen!) < const Duration(hours: 3);

    final rows = <Widget>[];
    String? lastDay;
    for (final i in shown) {
      final d = DateTime.fromMillisecondsSinceEpoch(i.at);
      final title = dayTitle(d);
      if (title != lastDay) {
        lastDay = title;
        rows.add(Padding(
          padding: const EdgeInsets.fromLTRB(4, 14, 4, 8),
          child: Text(title, style: TextStyle(fontWeight: FontWeight.w700, color: scheme.onSurfaceVariant)),
        ));
      }
      rows.add(Padding(padding: const EdgeInsets.only(bottom: 8), child: smsCard(i)));
    }

    return RefreshIndicator(
      onRefresh: load,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
        children: [
          // The phone, at a glance, and the one button that puts most things right.
          Card(
            elevation: 0,
            color: scheme.surfaceContainerHighest.withAlpha(150),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 14, 12, 14),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(dayTitle(now), style: TextStyle(color: scheme.onSurfaceVariant, fontSize: 13)),
                        const SizedBox(height: 4),
                        Row(children: [
                          Container(width: 9, height: 9, decoration: BoxDecoration(shape: BoxShape.circle, color: live ? const Color(0xFF16A34A) : const Color(0xFFDC2626))),
                          const SizedBox(width: 7),
                          Expanded(
                            child: Text(
                              live ? 'SMS forward চালু' : (smsOn ? 'Server-এ খবর যাচ্ছে না' : 'SMS forward বন্ধ'),
                              style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700),
                            ),
                          ),
                        ]),
                        const SizedBox(height: 2),
                        Text(lastSeen != null ? 'Server-এ শেষ খবর ${agoText(lastSeen!)}' : 'Server-এ এখনো খবর যায়নি',
                            style: TextStyle(color: scheme.onSurfaceVariant, fontSize: 12.5)),
                      ],
                    ),
                  ),
                  FilledButton.tonalIcon(
                    onPressed: syncing ? null : sync,
                    icon: syncing ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.sync),
                    label: const Text('Sync'),
                  ),
                ],
              ),
            ),
          ),
          if (serverError != null)
            Padding(padding: const EdgeInsets.only(top: 8), child: Text(serverError!, style: const TextStyle(color: Color(0xFFD97706), fontSize: 12.5))),
          const SizedBox(height: 12),
          GridView.count(
            crossAxisCount: 2,
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            mainAxisSpacing: 10,
            crossAxisSpacing: 10,
            childAspectRatio: 1.3,
            children: [
              statTile('আজ এসেছে', taka(today.fold<int>(0, (s, i) => s + (i.amount ?? 0))), Icons.south_west, brand, sub: '${today.length} টা SMS'),
              statTile('আজ invoice-এ মিলেছে', '${today.where((i) => i.status == 'matched').length}', Icons.task_alt, const Color(0xFF16A34A),
                  onTap: () => setState(() => filter = 'matched')),
              statTile('অপেক্ষায়', '${all.where((i) => i.status == 'pending').length}', Icons.schedule_send, const Color(0xFFD97706),
                  onTap: () => setState(() => filter = 'pending')),
              statTile('আটকে / মেলেনি', '${all.where((i) => i.status == 'failed').length} / ${all.where((i) => i.status == 'unmatched').length}',
                  Icons.error_outline, const Color(0xFFDC2626), onTap: () => setState(() => filter = 'failed')),
            ],
          ),
          const SizedBox(height: 12),
          if (week.isNotEmpty) weekChart(),
          const SizedBox(height: 14),
          TextField(
            decoration: InputDecoration(
              prefixIcon: const Icon(Icons.search),
              hintText: 'TrxID, নম্বর, invoice বা client খুঁজুন',
              filled: true,
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide.none),
              isDense: true,
            ),
            onChanged: (v) => setState(() => search = v.trim()),
          ),
          const SizedBox(height: 10),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(children: [
              for (final e in filters.entries)
                Padding(
                  padding: const EdgeInsets.only(right: 8),
                  child: ChoiceChip(label: Text(e.value), selected: filter == e.key, onSelected: (_) => setState(() => filter = e.key)),
                ),
            ]),
          ),
          if (loading) const Padding(padding: EdgeInsets.all(32), child: Center(child: CircularProgressIndicator())),
          if (!loading && rows.isEmpty)
            Padding(
              padding: const EdgeInsets.all(32),
              child: Center(child: Text(all.isEmpty ? 'এখনো কোনো payment SMS আসেনি।' : 'এখানে কিছু নেই।', style: TextStyle(color: scheme.onSurfaceVariant))),
            ),
          ...rows,
        ],
      ),
    );
  }

  // ------------------------------------------------------------------ senders

  Future<void> saveDisabled() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('sms.disabled', disabled.join(','));
  }

  Future<void> changeSender(String name, {required bool add}) async {
    final result = await widget.site.request(add ? 'POST' : 'DELETE', '/app/sms-senders', {'name': name});
    final body = result?.body;
    if (result?.status == 200 && body is Map) {
      final prefs = await SharedPreferences.getInstance();
      final forms = (body['forms'] as List?) ?? [];
      if (forms.isNotEmpty) {
        await prefs.setString('sms.senders', forms.join(','));
      }
      await load(quiet: true);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(add ? '$name যোগ হয়েছে — এখন থেকে এর SMS-ও যাবে।' : '$name বাদ দেওয়া হয়েছে।')));
      }
    } else if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('হলো না — নেট আর login দেখুন।')));
    }
  }

  void addSheet() {
    final input = TextEditingController();
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) {
        var query = '';
        return StatefulBuilder(
          builder: (context, setSheet) {
            final have = senders.map((s) => '${s['name']}'.toLowerCase().replaceAll(' ', '')).toSet();
            List<String> pick(String kind) => ((catalog[kind] as List?) ?? [])
                .map((e) => '$e')
                .where((n) => !have.contains(n.toLowerCase().replaceAll(' ', '')) && n.toLowerCase().contains(query.toLowerCase()))
                .toList();
            return Padding(
              padding: EdgeInsets.fromLTRB(20, 0, 20, MediaQuery.of(context).viewInsets.bottom + 20),
              child: SizedBox(
                height: MediaQuery.of(context).size.height * .75,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('Sender যোগ করুন', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
                    const SizedBox(height: 4),
                    Text('তালিকা থেকে বাছুন, বা SMS-এ ঠিক যে নামে আসে সেটা লিখুন।', style: TextStyle(color: Theme.of(context).colorScheme.onSurfaceVariant)),
                    const SizedBox(height: 12),
                    TextField(
                      controller: input,
                      decoration: InputDecoration(
                        hintText: 'যেমন: BRAC BANK বা 01844242613',
                        prefixIcon: const Icon(Icons.search),
                        suffixIcon: IconButton(
                          icon: const Icon(Icons.add_circle),
                          tooltip: 'যোগ করুন',
                          onPressed: () {
                            final name = input.text.trim();
                            if (name.isEmpty) return;
                            Navigator.of(context).pop();
                            changeSender(name, add: true);
                          },
                        ),
                        filled: true,
                        border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide.none),
                      ),
                      onChanged: (v) => setSheet(() => query = v.trim()),
                    ),
                    const SizedBox(height: 12),
                    Expanded(
                      child: ListView(
                        children: [
                          for (final kind in ['mfs', 'bank']) ...[
                            Padding(
                              padding: const EdgeInsets.symmetric(vertical: 8),
                              child: Text(kind == 'mfs' ? 'Mobile wallet' : 'Bank', style: const TextStyle(fontWeight: FontWeight.w700)),
                            ),
                            Wrap(
                              spacing: 8,
                              runSpacing: 8,
                              children: [
                                for (final name in pick(kind))
                                  ActionChip(
                                    avatar: const Icon(Icons.add, size: 16),
                                    label: Text(name),
                                    onPressed: () {
                                      Navigator.of(context).pop();
                                      changeSender(name, add: true);
                                    },
                                  ),
                              ],
                            ),
                          ],
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            );
          },
        );
      },
    );
  }

  Widget sendersTab() {
    final scheme = Theme.of(context).colorScheme;
    return RefreshIndicator(
      onRefresh: load,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
        children: [
          Row(children: [
            const Expanded(child: Text('যাদের SMS যায়', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800))),
            IconButton(onPressed: load, icon: const Icon(Icons.refresh), tooltip: 'নতুন করে আনো'),
            FilledButton.icon(onPressed: addSheet, icon: const Icon(Icons.add), label: const Text('যোগ')),
          ]),
          Text('বন্ধ করলে শুধু এই phone থেকে ওই sender-এর SMS যাবে না। মুছলে server-ও আর চিনবে না।',
              style: TextStyle(color: scheme.onSurfaceVariant, fontSize: 12.5)),
          const SizedBox(height: 10),
          for (final s in senders)
            Card(
              elevation: 0,
              margin: const EdgeInsets.only(bottom: 8),
              color: scheme.surfaceContainerHighest.withAlpha(110),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
              child: ListTile(
                leading: CircleAvatar(
                  backgroundColor: brand.withAlpha(40),
                  child: Icon(s['kind'] == 'bank' ? Icons.account_balance : Icons.account_balance_wallet, color: brand, size: 20),
                ),
                title: Text('${s['name']}', style: const TextStyle(fontWeight: FontWeight.w700)),
                subtitle: Text(
                  disabled.contains('${s['name']}')
                      ? 'বন্ধ — এই phone থেকে যায় না'
                      : '${switch (s['kind']) { 'bkash' => 'bKash', 'nagad' => 'Nagad', 'rocket' => 'Rocket', _ => 'Bank' }} · চালু, SMS যাচ্ছে',
                ),
                trailing: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    if (s['fixed'] != true)
                      IconButton(
                        icon: const Icon(Icons.delete_outline),
                        tooltip: 'মুছুন',
                        onPressed: () async {
                          final yes = await showDialog<bool>(
                            context: context,
                            builder: (c) => AlertDialog(
                              title: Text('${s['name']} মুছবেন?'),
                              content: const Text('এর SMS আর server-এ যাবে না, কোনো payment-ও মিলবে না।'),
                              actions: [
                                TextButton(onPressed: () => Navigator.of(c).pop(false), child: const Text('না')),
                                FilledButton(onPressed: () => Navigator.of(c).pop(true), child: const Text('মুছুন')),
                              ],
                            ),
                          );
                          if (yes == true) await changeSender('${s['name']}', add: false);
                        },
                      ),
                    Switch(
                      value: !disabled.contains('${s['name']}'),
                      onChanged: (on) async {
                        setState(() => on ? disabled.remove('${s['name']}') : disabled.add('${s['name']}'));
                        await saveDisabled();
                      },
                    ),
                  ],
                ),
              ),
            ),
          if (senders.isEmpty && !loading)
            Padding(padding: const EdgeInsets.all(24), child: Text('তালিকা আনা যায়নি — নেট দেখে উপর থেকে টেনে আবার চেষ্টা করুন।', style: TextStyle(color: scheme.onSurfaceVariant))),
        ],
      ),
    );
  }

  // ------------------------------------------------------------------ settings

  Widget section(String title, List<Widget> children) {
    final scheme = Theme.of(context).colorScheme;
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(padding: const EdgeInsets.fromLTRB(4, 6, 4, 8), child: Text(title, style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w800, color: brand, letterSpacing: .4))),
          Card(
            elevation: 0,
            margin: EdgeInsets.zero,
            color: scheme.surfaceContainerHighest.withAlpha(110),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
            child: Column(children: children),
          ),
        ],
      ),
    );
  }

  Widget check(bool ok, String title, String sub, {String? fix, VoidCallback? onFix}) {
    return ListTile(
      leading: Icon(ok ? Icons.check_circle : Icons.error, color: ok ? const Color(0xFF16A34A) : const Color(0xFFDC2626)),
      title: Text(title),
      subtitle: Text(sub),
      trailing: fix == null ? null : TextButton(onPressed: onFix, child: Text(fix)),
    );
  }

  Widget bar(String label, int value, int top, Color color) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 5),
      child: Row(children: [
        SizedBox(width: 74, child: Text(label, style: const TextStyle(fontSize: 13))),
        Expanded(
          child: ClipRRect(
            borderRadius: BorderRadius.circular(6),
            child: LinearProgressIndicator(value: top == 0 ? 0 : value / top, minHeight: 12, color: color, backgroundColor: color.withAlpha(30)),
          ),
        ),
        SizedBox(width: 42, child: Text('$value', textAlign: TextAlign.right, style: const TextStyle(fontWeight: FontWeight.w700))),
      ]),
    );
  }

  Widget settingsTab() {
    final all = items;
    final counts = {
      'synced': all.where((i) => ['matched', 'unmatched', 'refund', 'synced'].contains(i.status)).length,
      'pending': all.where((i) => i.status == 'pending').length,
      'failed': all.where((i) => i.status == 'failed').length,
      'skipped': all.where((i) => i.status == 'skipped').length,
    };
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
      children: [
        section('PHONE-এর অবস্থা', [
          SwitchListTile(
            value: smsOn,
            title: const Text('এই phone থেকে payment SMS পাঠাও'),
            subtitle: const Text('Wallet-এর SIM যে phone-এ, শুধু সেখানে'),
            onChanged: (on) async {
              final prefs = await SharedPreferences.getInstance();
              if (!on) {
                await prefs.remove('sms.url');
                await prefs.setBool('sms.off', true);
              } else {
                final why = await widget.site.enableSms();
                if (why != null && mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(why)));
                }
              }
              await load();
            },
          ),
          check(smsAllowed, 'SMS পড়ার অনুমতি', smsAllowed ? 'দেওয়া আছে' : 'নেই — payment ধরা যাবে না', fix: smsAllowed ? null : 'দিন', onFix: () async {
            final status = await Permission.sms.request();
            if (status.isPermanentlyDenied || status.isRestricted) await openAppSettings();
            await load();
          }),
          check(batteryFree, 'Battery saver থেকে বাদ', batteryFree ? 'হ্যাঁ — app বন্ধ থাকলেও SMS যাবে' : 'না — phone app থামিয়ে দিতে পারে',
              fix: batteryFree ? null : 'ঠিক করুন', onFix: () async {
            await Permission.ignoreBatteryOptimizations.request();
            await load();
          }),
          check(
            lastSeen != null && DateTime.now().difference(lastSeen!) < const Duration(hours: 3),
            'Server-এর সাথে যোগাযোগ',
            lastSeen != null ? 'শেষ খবর ${agoText(lastSeen!)}${lastOk != null ? ' · শেষ SMS ${agoText(lastOk!)}' : ''}' : 'এখনো খবর যায়নি',
            fix: 'পরীক্ষা',
            onFix: () async {
              final ok = await smsChannel.invokeMethod<bool>('beat').catchError((_) => false) ?? false;
              await load();
              if (mounted) {
                ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ok ? 'Server পেয়েছে — সব ঠিক আছে।' : 'Server-এ পৌঁছাল না — নেট দেখুন।')));
              }
            },
          ),
          const ListTile(
            leading: Icon(Icons.info_outline),
            title: Text('Phone-এর App info-তে'),
            subtitle: Text('"Manage app if unused" বন্ধ রাখুন, নাহলে Android নিজে SMS-এর অনুমতি তুলে নিতে পারে।'),
          ),
        ]),
        section('হিসাব', [
          const SizedBox(height: 8),
          bar('পৌঁছেছে', counts['synced']!, all.length, const Color(0xFF16A34A)),
          bar('অপেক্ষায়', counts['pending']!, all.length, const Color(0xFFD97706)),
          bar('আটকে', counts['failed']!, all.length, const Color(0xFFDC2626)),
          bar('OTP, বাদ', counts['skipped']!, all.length, Colors.grey),
          const SizedBox(height: 8),
        ]),
        section('বাদ পড়া SMS', [
          ListTile(
            leading: const Icon(Icons.manage_search),
            title: const Text('Phone-এর inbox থেকে খুঁজে পাঠাও'),
            subtitle: const Text('Forward বন্ধ থাকার সময় যা এসেছিল — একই TrxID দুবার লেখা হয় না'),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
            child: Wrap(spacing: 8, children: [
              for (final days in [1, 3, 7, 30])
                OutlinedButton(
                  onPressed: () async {
                    final n = await smsChannel.invokeMethod<int>('importInbox', {'days': days}).catchError((_) => -1) ?? -1;
                    await smsChannel.invokeMethod('flush').catchError((_) => null);
                    await Future<void>.delayed(const Duration(seconds: 2));
                    await load();
                    if (mounted) {
                      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                        content: Text(n < 0 ? 'Inbox পড়া গেল না — SMS-এর অনুমতি দেখুন।' : n == 0 ? 'বাদ পড়া কোনো SMS নেই।' : '$n টা SMS পাঠানো হলো।'),
                      ));
                    }
                  },
                  child: Text(days == 1 ? 'আজ' : '$days দিন'),
                ),
            ]),
          ),
        ]),
        section('এই শব্দ থাকলে যাবে না', [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
            child: Text('OTP, PIN, code-এর SMS phone থেকে বের হয় না (টাকা আসার SMS সবসময় যায়)।',
                style: TextStyle(color: Theme.of(context).colorScheme.onSurfaceVariant, fontSize: 12.5)),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 4, 16, 12),
            child: Wrap(spacing: 8, runSpacing: 8, children: [
              for (final w in words)
                InputChip(
                  label: Text(w),
                  onDeleted: () async {
                    setState(() => words.remove(w));
                    final prefs = await SharedPreferences.getInstance();
                    await prefs.setString('sms.ignore', words.join('\n'));
                  },
                ),
              ActionChip(
                avatar: const Icon(Icons.add, size: 16),
                label: const Text('শব্দ যোগ'),
                onPressed: () async {
                  final input = TextEditingController();
                  final word = await showDialog<String>(
                    context: context,
                    builder: (c) => AlertDialog(
                      title: const Text('নতুন শব্দ'),
                      content: TextField(controller: input, autofocus: true, decoration: const InputDecoration(hintText: 'যেমন: password')),
                      actions: [
                        TextButton(onPressed: () => Navigator.of(c).pop(), child: const Text('বাতিল')),
                        FilledButton(onPressed: () => Navigator.of(c).pop(input.text.trim().toLowerCase()), child: const Text('যোগ')),
                      ],
                    ),
                  );
                  if (word != null && word.isNotEmpty && !words.contains(word)) {
                    setState(() => words.add(word));
                    final prefs = await SharedPreferences.getInstance();
                    await prefs.setString('sms.ignore', words.join('\n'));
                  }
                },
              ),
            ]),
          ),
        ]),
        section('জায়গা', [
          for (final days in [7, 30])
            ListTile(
              leading: const Icon(Icons.cleaning_services_outlined),
              title: Text('$days দিনের পুরনো তালিকা মুছুন'),
              subtitle: const Text('শুধু এই phone-এর তালিকা — server-এর হিসাব থাকে'),
              onTap: () async {
                final n = await smsChannel.invokeMethod<int>('cleanLog', {'days': days}).catchError((_) => 0) ?? 0;
                await load();
                if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$n টা মুছে গেছে।')));
              },
            ),
        ]),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('SMS Center'),
        actions: [
          if (tab == 0)
            IconButton(onPressed: syncing ? null : sync, icon: const Icon(Icons.sync), tooltip: 'Sync'),
        ],
      ),
      body: [dashboard(), sendersTab(), settingsTab()][tab],
      bottomNavigationBar: NavigationBar(
        selectedIndex: tab,
        onDestinationSelected: (i) => setState(() => tab = i),
        destinations: const [
          NavigationDestination(icon: Icon(Icons.dashboard_outlined), selectedIcon: Icon(Icons.dashboard), label: 'Dashboard'),
          NavigationDestination(icon: Icon(Icons.filter_alt_outlined), selectedIcon: Icon(Icons.filter_alt), label: 'Sender'),
          NavigationDestination(icon: Icon(Icons.settings_outlined), selectedIcon: Icon(Icons.settings), label: 'Setting'),
        ],
      ),
    );
  }
}
