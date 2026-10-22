# subcontractor_app

A new Flutter project.

## Getting Started

This project is a starting point for a Flutter application.

A few resources to get you started if this is your first Flutter project:

- [Lab: Write your first Flutter app](https://docs.flutter.dev/get-started/codelab)
- [Cookbook: Useful Flutter samples](https://docs.flutter.dev/cookbook)

For help getting started with Flutter development, view the
[online documentation](https://docs.flutter.dev/), which offers tutorials,
samples, guidance, and a full API reference.
# subcontractor_app

The mobile app opens the QHSE web portal so login, account registration,
approval checks, and the subcontractor tutorial use the same PHP workflows as
the website.

## Portal URL

The default URL targets this XAMPP project from a phone on the same Wi-Fi:
`http://172.16.200.21/QHSE_System/`.

For a deployed site, pass the site's HTTPS base URL:

```powershell
flutter run --dart-define=QHSE_WEB_BASE_URL=https://your-host/QHSE_System/
```

Keep the trailing slash so relative portal links resolve correctly. If the PC's
LAN address changes, update the default URL and matching Android HTTP exception,
or pass the current URL with `--dart-define`. The phone and PC must be on the
same Wi-Fi, and Windows Firewall must allow Apache connections.
samples, guidance on mobile development, and a full API reference.
