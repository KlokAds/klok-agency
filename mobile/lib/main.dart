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
const teal = Color(0xFF0D9488);
const rail = Color(0xFF11211E);
const paper = Color(0xFFF6F7F6);

final notifications = FlutterLocalNotificationsPlugin();
const smsChannel = MethodChannel('klok/sms');
const updateChannel = MethodChannel('klok/update');

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
  final title = message.notification?.title ?? data['title'] ?? 'Klok Finance';
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
        color: teal,
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

  // Payment SMS that waited for internet go now.
  unawaited(smsChannel.invokeMethod('flush').catchError((_) => null));

  runApp(const FinanceApp());
}

void go(String? url) {
  if (url != null && url.isNotEmpty) {
    opened.add(url);
  }
}

class FinanceApp extends StatelessWidget {
  const FinanceApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Klok Finance',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(colorSchemeSeed: teal, scaffoldBackgroundColor: paper, useMaterial3: true),
      // Over every screen (the site and the app's own settings alike).
      builder: (context, child) => LockGate(child: child ?? const SizedBox()),
      home: const SitePage(),
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
  static Future<bool> pinWithFinger() async => (await _p).getBool('lock.pin_finger') ?? true;

  static String _hash(String salt, String pin) => sha256.convert(utf8.encode('$salt:$pin')).toString();

  static Future<void> setPin(String pin) async {
    final p = await _p;
    final salt = base64Url.encode(List<int>.generate(16, (_) => Random.secure().nextInt(256)));
    await p.setString('lock.salt', salt);
    await p.setString('lock.pin', _hash(salt, pin));
  }

  static Future<bool> checkPin(String pin) async {
    final p = await _p;
    final salt = p.getString('lock.salt');
    final hash = p.getString('lock.pin');
    return salt != null && hash != null && _hash(salt, pin) == hash;
  }

  static Future<bool> hasPin() async => (await _p).getString('lock.pin') != null;

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
Future<bool> confirmIdentity(BuildContext context, {String reason = 'Klok Finance খুলতে'}) async {
  final mode = await Lock.mode();
  if (mode == 'off') {
    return true;
  }
  if (mode == 'bio') {
    return Lock.finger(reason);
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
  String mode = 'bio';
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
    mode = await Lock.mode();
    var ok = mode == 'off';
    try {
      if (mode == 'bio' || (mode == 'pin' && await Lock.pinWithFinger())) {
        ok = await Lock.finger('Klok Finance খুলতে আঙুলের ছাপ দিন');
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

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        widget.child,
        if (locked)
          Positioned.fill(
            child: Material(
              color: rail,
              child: mode == 'pin'
                ? PinPad(
                    title: 'PIN দিয়ে খুলুন',
                    onFinger: unlock,
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
                            const Icon(Icons.fingerprint, size: 76, color: Color(0xFF2DD4BF)),
                            const SizedBox(height: 18),
                            const Text('Klok Finance', style: TextStyle(fontSize: 22, fontWeight: FontWeight.w700, color: Colors.white)),
                            const SizedBox(height: 28),
                            FilledButton.icon(
                              style: FilledButton.styleFrom(backgroundColor: teal),
                              onPressed: unlock,
                              icon: const Icon(Icons.lock_open),
                              label: const Text('আঙুলের ছাপ দিয়ে খুলুন'),
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

/// Four to six digits. Five wrong tries in a row wait thirty seconds.
class PinPad extends StatefulWidget {
  const PinPad({super.key, required this.title, required this.onDone, this.onFinger, this.subtitle});

  final String title;
  final String? subtitle;
  final Future<bool> Function(String pin) onDone;
  final VoidCallback? onFinger;

  @override
  State<PinPad> createState() => _PinPadState();
}

class _PinPadState extends State<PinPad> {
  String pin = '';
  String? error;
  int wrong = 0;
  DateTime? waitUntil;

  Future<void> press(String key) async {
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
      final ok = await widget.onDone(pin);
      if (!mounted) {
        return;
      }
      if (ok) {
        wrong = 0;
        setState(() => pin = '');
      } else {
        wrong++;
        HapticFeedback.heavyImpact();
        if (wrong >= 5) {
          wrong = 0;
          waitUntil = DateTime.now().add(const Duration(seconds: 30));
        }
        setState(() {
          pin = '';
          error = 'PIN মেলেনি';
        });
      }
      return;
    }
    if (pin.length < 6) {
      setState(() {
        pin += key;
        error = null;
      });
    }
  }

  Widget key(String label, {IconData? icon}) {
    return SizedBox(
      width: 76,
      height: 76,
      child: TextButton(
        style: TextButton.styleFrom(foregroundColor: Colors.white, shape: const CircleBorder()),
        onPressed: () => press(label),
        child: icon != null ? Icon(icon, size: 26) : Text(label, style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w500)),
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
            const Spacer(),
            const Icon(Icons.lock_outline, color: Color(0xFF2DD4BF), size: 40),
            const SizedBox(height: 14),
            Text(widget.title, style: const TextStyle(color: Colors.white, fontSize: 19, fontWeight: FontWeight.w600)),
            if (widget.subtitle != null) ...[
              const SizedBox(height: 6),
              Text(widget.subtitle!, style: const TextStyle(color: Colors.white60, fontSize: 13.5)),
            ],
            const SizedBox(height: 22),
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: List.generate(
                6,
                (i) => Container(
                  margin: const EdgeInsets.symmetric(horizontal: 7),
                  width: 13,
                  height: 13,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: i < pin.length ? const Color(0xFF2DD4BF) : Colors.transparent,
                    border: Border.all(color: i < 4 || i < pin.length ? Colors.white54 : Colors.white24, width: 1.5),
                  ),
                ),
              ),
            ),
            SizedBox(height: 28, child: Center(child: Text(error ?? '', style: const TextStyle(color: Color(0xFFFCA5A5))))),
            for (final row in [
              ['1', '2', '3'],
              ['4', '5', '6'],
              ['7', '8', '9'],
            ])
              Row(mainAxisAlignment: MainAxisAlignment.center, children: row.map((k) => key(k)).toList()),
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                widget.onFinger != null
                    ? SizedBox(
                        width: 76,
                        height: 76,
                        child: IconButton(onPressed: widget.onFinger, icon: const Icon(Icons.fingerprint, color: Colors.white70, size: 30)),
                      )
                    : key('<', icon: Icons.backspace_outlined),
                key('0'),
                key('ok', icon: Icons.check_rounded),
              ],
            ),
            if (widget.onFinger != null) TextButton(onPressed: () => press('<'), child: const Text('মুছুন', style: TextStyle(color: Colors.white60))),
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
      body: PinPad(
        title: 'PIN দিন',
        subtitle: title,
        onDone: (pin) async {
          final ok = await Lock.checkPin(pin);
          if (ok && context.mounted) {
            Navigator.of(context).pop(true);
          }
          return ok;
        },
      ),
    );
  }
}

/// A new PIN, typed twice.
class NewPinPage extends StatefulWidget {
  const NewPinPage({super.key});

  @override
  State<NewPinPage> createState() => _NewPinPageState();
}

class _NewPinPageState extends State<NewPinPage> {
  String? first;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: PinPad(
        key: ValueKey(first),
        title: first == null ? 'নতুন PIN দিন (৪–৬ সংখ্যা)' : 'আবার একই PIN দিন',
        onDone: (pin) async {
          if (first == null) {
            setState(() => first = pin);
            return true;
          }
          if (pin != first) {
            setState(() => first = null);
            return false;
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
      settings: PullToRefreshSettings(color: teal),
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
    final target = url.startsWith('http') ? url : '$site${url.startsWith('/') ? '' : '/'}$url';
    web?.loadUrl(urlRequest: URLRequest(url: WebUri(target)));
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
      return 'SMS পড়ার অনুমতি দেওয়া হয়নি। Phone-এর Settings › Apps › Klok Finance › Permissions থেকে দিন।';
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
    return null;
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
                initialUrlRequest: URLRequest(url: WebUri('$site${openFirst ?? '/dashboard'}')),
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
                  controller.addJavaScriptHandler(handlerName: 'appSettings', callback: (_) => openSettings());
                  controller.addJavaScriptHandler(handlerName: 'theme', callback: setBars);
                  controller.addJavaScriptHandler(handlerName: 'signingOut', callback: (_) async {
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
              if (progress < 1) LinearProgressIndicator(value: progress, minHeight: 2, color: teal, backgroundColor: Colors.transparent),
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
                            const Icon(Icons.wifi_off_rounded, size: 56, color: teal),
                            const SizedBox(height: 14),
                            const Text('নেট নেই', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w700)),
                            const SizedBox(height: 8),
                            const Text(
                              'এই পাতাটা আগে খোলা হয়নি, তাই phone-এ রাখা নেই। আগে খোলা পাতা আর quick entry নেট ছাড়াও চলে; payment SMS নেট এলে নিজে থেকে যাবে।',
                              textAlign: TextAlign.center,
                            ),
                            const SizedBox(height: 18),
                            FilledButton.icon(
                              style: FilledButton.styleFrom(backgroundColor: teal),
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
  String mode = 'bio';
  int after = 1;
  bool pinFinger = true;
  bool smsOn = false;
  bool smsBusy = false;
  String? smsError;
  int pending = 0;
  DateTime? lastOk;
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
      version = '${info.version} (${info.buildNumber})';
    });
  }

  Future<void> setMode(String value) async {
    if (value == mode || !await confirmIdentity(context, reason: 'App lock বদলাতে')) {
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

  String ago(DateTime at) {
    final d = DateTime.now().difference(at);
    if (d.inMinutes < 1) return 'এইমাত্র';
    if (d.inHours < 1) return '${d.inMinutes} মিনিট আগে';
    if (d.inDays < 1) return '${d.inHours} ঘণ্টা আগে';
    return '${d.inDays} দিন আগে';
  }

  @override
  Widget build(BuildContext context) {
    const head = TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: teal, letterSpacing: .3);
    return Scaffold(
      appBar: AppBar(title: Text(smsBuild ? 'App lock ও SMS' : 'App lock'), backgroundColor: rail, foregroundColor: Colors.white),
      body: ListView(
        padding: const EdgeInsets.symmetric(vertical: 8),
        children: [
          const Padding(padding: EdgeInsets.fromLTRB(16, 16, 16, 4), child: Text('APP LOCK', style: head)),
          for (final entry in Lock.modes.entries)
            RadioListTile<String>(
              value: entry.key,
              groupValue: mode,
              onChanged: (v) => setMode(v!),
              title: Text(entry.value),
              subtitle: entry.key == 'bio'
                  ? const Text('আঙুল না চিনলে phone-এর PIN/pattern')
                  : entry.key == 'off'
                      ? const Text('যে phone হাতে পাবে সে-ই খুলতে পারবে')
                      : null,
            ),
          if (mode == 'pin') ...[
            SwitchListTile(
              value: pinFinger,
              onChanged: (v) async {
                final prefs = await SharedPreferences.getInstance();
                await prefs.setBool('lock.pin_finger', v);
                setState(() => pinFinger = v);
              },
              title: const Text('আঙুলের ছাপেও খুলবে'),
            ),
            ListTile(leading: const Icon(Icons.password), title: const Text('PIN বদলান'), onTap: changePin),
          ],
          if (mode != 'off')
            ListTile(
              leading: const Icon(Icons.timer_outlined),
              title: const Text('কতক্ষণ পর আবার lock'),
              trailing: DropdownButton<int>(
                value: after,
                underline: const SizedBox(),
                items: Lock.afters.entries.map((e) => DropdownMenuItem(value: e.key, child: Text(e.value))).toList(),
                onChanged: (v) async {
                  final prefs = await SharedPreferences.getInstance();
                  await prefs.setInt('lock.after', v!);
                  setState(() => after = v);
                },
              ),
            ),
          if (smsBuild) ...[
          const Divider(height: 32),
          const Padding(padding: EdgeInsets.fromLTRB(16, 0, 16, 4), child: Text('PAYMENT SMS', style: head)),
          SwitchListTile(
            value: smsOn,
            onChanged: smsBusy ? null : toggleSms,
            title: const Text('এই phone থেকে payment SMS পাঠাও'),
            subtitle: const Text(
              'Wallet-এর SIM যে phone-এ, শুধু সেখানে (Owner)। bKash, NAGAD, 16216 আর bank-এর SMS ছাড়া কিছুই phone থেকে যায় না।',
            ),
          ),
          if (smsBusy) const LinearProgressIndicator(color: teal),
          if (smsError != null)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
              child: Text(smsError!, style: const TextStyle(color: Color(0xFFB91C1C))),
            ),
          if (smsOn)
            ListTile(
              leading: Icon(pending > 0 ? Icons.schedule_send : Icons.check_circle_outline, color: pending > 0 ? Colors.orange : teal),
              title: Text(pending > 0 ? '$pending টা SMS নেট-এর অপেক্ষায়' : 'সব SMS পৌঁছেছে'),
              subtitle: Text(lastOk != null ? 'শেষ পাঠানো ${ago(lastOk!)}' : 'এখনো কোনো SMS আসেনি'),
              trailing: pending > 0
                  ? TextButton(
                      onPressed: () async {
                        await smsChannel.invokeMethod('flush');
                        await Future<void>.delayed(const Duration(seconds: 3));
                        await load();
                      },
                      child: const Text('এখনই পাঠাও'),
                    )
                  : null,
            ),
          ],
          const Divider(height: 32),
          ListTile(
            leading: const Icon(Icons.info_outline),
            title: const Text('Version'),
            subtitle: Text(smsBuild ? '$version · SMS' : version),
            onTap: () {
              widget.site.checkedUpdate = null;
              widget.site.checkUpdate();
            },
          ),
        ],
      ),
    );
  }
}
