import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:file_picker/file_picker.dart';
import 'package:geolocator/geolocator.dart';
import 'package:onesignal_flutter/onesignal_flutter.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:webview_flutter/webview_flutter.dart';
import 'package:webview_flutter_android/webview_flutter_android.dart';

const _appUrl = 'https://mobile.our-qiq.com/login';
const _oneSignalAppId = 'f4ce6025-029b-4435-a8c2-1b28ae6b08d8';
const _productHost = 'mobile.our-qiq.com';
const _productOrigin = 'https://mobile.our-qiq.com';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await SystemChrome.setEnabledSystemUIMode(SystemUiMode.edgeToEdge);
  SystemChrome.setSystemUIOverlayStyle(const SystemUiOverlayStyle(
    statusBarColor: Colors.transparent,
    systemNavigationBarColor: Colors.transparent,
    systemNavigationBarDividerColor: Colors.transparent,
    statusBarIconBrightness: Brightness.dark,
    systemNavigationBarIconBrightness: Brightness.dark,
  ));
  OneSignal.initialize(_oneSignalAppId);
  runApp(const AlMunjazApp());
}

class AlMunjazApp extends StatelessWidget {
  const AlMunjazApp({super.key});

  @override
  Widget build(BuildContext context) => MaterialApp(
    debugShowCheckedModeBanner: false,
    title: 'المنجز السريع',
    theme: ThemeData(useMaterial3: true),
    home: const _WebAppShell(),
  );
}

class _WebAppShell extends StatefulWidget {
  const _WebAppShell();

  @override
  State<_WebAppShell> createState() => _WebAppShellState();
}

class _WebAppShellState extends State<_WebAppShell> {
  late final WebViewController _controller;
  int _progress = 0;
  bool _failed = false;
  DateTime? _lastExitAttempt;
  bool _pageReady = false;
  String? _pendingNotificationPath;

  @override
  void initState() {
    super.initState();
    _controller = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..addJavaScriptChannel('NativeApp', onMessageReceived: _onNativeMessage)
      ..setNavigationDelegate(
        NavigationDelegate(
          onProgress: (value) {
            if (mounted) setState(() => _progress = value);
          },
          onPageStarted: (_) {
            if (mounted) setState(() => _failed = false);
          },
          onPageFinished: (_) async {
            _pageReady = true;
            await _prepareNativePage();
            await _openPendingNotification();
          },
          onWebResourceError: (error) {
            if (error.isForMainFrame == true && mounted) {
              setState(() => _failed = true);
            }
          },
          onNavigationRequest: (request) => _handleNavigation(request.url),
        ),
      )
      ..loadRequest(Uri.parse(_appUrl));

    // The OneSignal SDK buffers a notification press which opened a cold
    // start. Registering immediately here therefore covers foreground,
    // background, terminated and lock-screen interactions.
    OneSignal.Notifications.addClickListener(_onNotificationClick);
    OneSignal.Notifications.addForegroundWillDisplayListener(
      _onForegroundNotification,
    );
    OneSignal.Notifications.addPermissionObserver(_onNotificationPermission);
    // A token may arrive after the Android permission dialog closes. Keep
    // the web switch synchronized with the actual OneSignal subscription,
    // rather than guessing after a fixed delay.
    OneSignal.User.pushSubscription.addObserver(_onPushSubscriptionChanged);

    final platformController = _controller.platform;
    if (platformController is AndroidWebViewController) {
      platformController.setTextZoom(100);
      // Android's WebView does not provide a reliable document chooser for
      // every `input[type=file]` by itself.  Supplying it here makes the
      // registration documents open the phone's real Gallery/Files picker.
      platformController.setOnShowFileSelector(_selectFilesForWebPage);
      platformController.setGeolocationPermissionsPromptCallbacks(
        onShowPrompt: (_) async => GeolocationPermissionsResponse(
          allow: await _ensureLocationPermission(),
          retain: true,
        ),
      );
    }
  }

  void _onForegroundNotification(OSNotificationWillDisplayEvent event) {
    // The Vue shell receives the same persisted notification through Pusher
    // and renders one branded in-app banner. Suppress Android's second banner
    // while this activity is visible, but never suppress background delivery.
    OneSignal.Notifications.preventDefault(event.notification.notificationId);
  }

  void _onNotificationPermission(bool granted) {
    _dispatchNotificationState();
  }

  void _onPushSubscriptionChanged(OSPushSubscriptionChangedState _) {
    _dispatchNotificationState();
  }

  void _onNotificationClick(OSNotificationClickEvent event) {
    final data = event.notification.additionalData ?? const <String, dynamic>{};
    final candidate = data['url']?.toString()
        ?? event.result.url
        ?? event.notification.launchUrl;
    final path = _safeInternalPath(candidate);
    if (path == null) return;

    _pendingNotificationPath = path;
    _openPendingNotification();
  }

  String? _safeInternalPath(String? candidate) {
    if (candidate == null || candidate.isEmpty) return null;
    final uri = Uri.tryParse(candidate);
    if (uri == null) return null;

    if (!uri.hasScheme && candidate.startsWith('/app/')) return candidate;
    if (uri.scheme == 'https' && uri.host == _productHost && uri.path.startsWith('/app/')) {
      return '${uri.path}${uri.hasQuery ? '?${uri.query}' : ''}';
    }

    return null;
  }

  Future<void> _openPendingNotification() async {
    final path = _pendingNotificationPath;
    if (!_pageReady || path == null) return;

    _pendingNotificationPath = null;
    await _controller.loadRequest(Uri.parse('$_productOrigin$path'));
  }

  Future<void> _dispatchNotificationState() async {
    if (!_pageReady) return;

    final permission = OneSignal.Notifications.permission;
    final subscription = OneSignal.User.pushSubscription;
    final subscribed = permission && (subscription.optedIn ?? false);
    final ready = subscribed &&
        (subscription.id?.isNotEmpty ?? false) &&
        (subscription.token?.isNotEmpty ?? false);

    await _controller.runJavaScript(
      "window.dispatchEvent(new CustomEvent('almunjaz:native-notifications', {detail: {enabled: ${ready ? 'true' : 'false'}, permission: ${permission ? 'true' : 'false'}, subscribed: ${subscribed ? 'true' : 'false'}}}));",
    );
  }

  Future<List<String>> _selectFilesForWebPage(FileSelectorParams params) async {
    // Courier verification requires document photos only. Keeping this list
    // image-only prevents an irrelevant PDF picker choice in the Android app.
    const extensions = ['jpg', 'jpeg', 'png', 'webp'];
    final files = params.mode == FileSelectorMode.openMultiple
        ? await FilePicker.pickFiles(
            type: FileType.custom,
            allowedExtensions: extensions,
          )
        : [
            ?await FilePicker.pickFile(
              type: FileType.custom,
              allowedExtensions: extensions,
            ),
          ];

    return files
        .map((file) => file.path)
        .whereType<String>()
        .where((path) => path.isNotEmpty)
        .toList(growable: false);
  }

  /// WebView has its own geolocation permission callback in addition to the
  /// Android app permission. Both roles therefore use the same native prompt
  /// when they press "activate location" in the branded web sheet.
  Future<bool> _ensureLocationPermission() async {
    if (!await Geolocator.isLocationServiceEnabled()) return false;

    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }

    return permission == LocationPermission.always ||
        permission == LocationPermission.whileInUse;
  }

  Future<void> _prepareNativePage() async {
    // The website recognises this flag to hide PWA-only controls inside the
    // installed Android application. It also prevents Android font scaling
    // from changing the calibrated web layout.
    await _controller.runJavaScript('''
      document.documentElement.dataset.nativeApp = 'android';
      document.documentElement.style.webkitTextSizeAdjust = '100%';
      if (!document.getElementById('almunjaz-native-shell-style')) {
        const style = document.createElement('style');
        style.id = 'almunjaz-native-shell-style';
        style.textContent = '.pwa-install-banner { display: none !important; }';
        document.head.appendChild(style);
      }
      window.dispatchEvent(new Event('almunjaz:native-ready'));
    ''');
    await _dispatchNotificationState();
  }

  Future<NavigationDecision> _handleNavigation(String value) async {
    final uri = Uri.tryParse(value);
    if (uri == null) return NavigationDecision.prevent;
    if (uri.host == _productHost || uri.host.isEmpty) {
      return NavigationDecision.navigate;
    }
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
    return NavigationDecision.prevent;
  }

  Future<void> _onNativeMessage(JavaScriptMessage message) async {
    final value = message.message;
    if (value.startsWith('login:')) {
      await OneSignal.login(value.substring('login:'.length));
      // Permission is always initiated by the app's visible switch. Do not
      // surprise a newly signed-in courier with an Android prompt.
      await _dispatchNotificationState();
    } else if (value == 'notifications:enable') {
      final granted = OneSignal.Notifications.permission ||
          await OneSignal.Notifications.requestPermission(true);
      if (granted) {
        await OneSignal.User.pushSubscription.optIn();
        // The FCM/OneSignal token is issued asynchronously after the Android
        // dialog. Wait briefly so the web switch reflects a real subscription,
        // not merely a granted runtime permission.
        await Future<void>.delayed(const Duration(seconds: 2));
      }
      await _dispatchNotificationState();
    } else if (value == 'notifications:disable') {
      // This intentionally stops only this device's OneSignal subscription.
      // Android's global notification permission remains granted; an app
      // cannot revoke an operating-system permission on the user's behalf.
      await OneSignal.User.pushSubscription.optOut();
      await _dispatchNotificationState();
    } else if (value == 'logout') {
      await OneSignal.logout();
    }
  }

  @override
  void dispose() {
    OneSignal.User.pushSubscription.removeObserver(_onPushSubscriptionChanged);
    super.dispose();
  }

  Future<void> _reload() async {
    setState(() => _failed = false);
    await _controller.loadRequest(Uri.parse(_appUrl));
  }

  /// Android's system back button follows the web application's own history
  /// first. It only closes the app after a deliberate second press on its
  /// first page, which prevents accidental exits while viewing an order.
  Future<bool> _handleSystemBack() async {
    if (await _controller.canGoBack()) {
      await _controller.goBack();
      return false;
    }

    final now = DateTime.now();
    final canExit = _lastExitAttempt != null &&
        now.difference(_lastExitAttempt!) < const Duration(seconds: 2);
    if (canExit) return true;

    _lastExitAttempt = now;
    if (mounted) {
      ScaffoldMessenger.of(context)
        ..hideCurrentSnackBar()
        ..showSnackBar(
          const SnackBar(
            content: Text('اضغط رجوع مرة أخرى للخروج من التطبيق'),
            duration: Duration(seconds: 2),
          ),
        );
    }
    return false;
  }

  @override
  Widget build(BuildContext context) => PopScope(
    canPop: false,
    onPopInvokedWithResult: (didPop, _) async {
      if (!didPop && await _handleSystemBack()) {
        await SystemNavigator.pop();
      }
    },
    child: Scaffold(
      body: Stack(
        children: [
          WebViewWidget(controller: _controller),
          if (_progress < 100 && !_failed)
            LinearProgressIndicator(value: _progress / 100),
          if (_failed)
            ColoredBox(
              color: Colors.white,
              child: Center(
                child: Padding(
                  padding: const EdgeInsets.all(28),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(Icons.wifi_off_rounded, size: 54),
                      const SizedBox(height: 16),
                      const Text(
                        'تعذر فتح المنجز السريع. تحقق من اتصال الإنترنت ثم أعد المحاولة.',
                        textAlign: TextAlign.center,
                      ),
                      const SizedBox(height: 18),
                      FilledButton.icon(
                        onPressed: _reload,
                        icon: const Icon(Icons.refresh),
                        label: const Text('إعادة المحاولة'),
                      ),
                    ],
                  ),
                ),
              ),
            ),
        ],
      ),
    ),
  );
}
