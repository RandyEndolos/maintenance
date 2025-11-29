import 'package:flutter/material.dart';
import 'package:flutter_dotenv/flutter_dotenv.dart';
import 'package:supabase_flutter/supabase_flutter.dart';
import 'screens/login_screen.dart';
import 'screens/dashboard_screen.dart';
import 'screens/request_form.dart';
import 'screens/requests_list.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  // Load environment (ignore if missing in release builds)
  try {
    await dotenv.load(fileName: '.env');
  } catch (_) {}

  final supabaseUrl = dotenv.env['SUPABASE_URL'] ?? '';
  final supabaseKey = dotenv.env['SUPABASE_ANON_KEY'] ?? '';

  String? initError;
  if (supabaseUrl.isEmpty || supabaseKey.isEmpty) {
    initError =
        'Missing SUPABASE_URL or SUPABASE_ANON_KEY. Copy `.env.example` to `.env` and set keys.';
  } else {
    try {
      await Supabase.initialize(
        url: supabaseUrl,
        anonKey: supabaseKey,
        authFlowType: AuthFlowType.pkce,
      );
    } catch (e) {
      initError = 'Supabase initialization failed: $e';
    }
  }

  runApp(MyApp(initError: initError));
}

class MyApp extends StatelessWidget {
  final String? initError;
  const MyApp({super.key, this.initError});

  @override
  Widget build(BuildContext context) {
    if (initError != null) {
      return MaterialApp(
        title: 'Maintenance - Error',
        home: Scaffold(
          appBar: AppBar(title: const Text('Initialization Error')),
          body: Padding(
            padding: const EdgeInsets.all(16.0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(initError!, style: const TextStyle(color: Colors.red)),
                const SizedBox(height: 12),
                const Text('Tips:'),
                const Text(
                    '- Create a `.env` file in the app root from `.env.example`.'),
                const Text(
                    '- Make sure `SUPABASE_URL` and `SUPABASE_ANON_KEY` are set.'),
                const SizedBox(height: 12),
                ElevatedButton(
                  onPressed: () => {},
                  child: const Text('OK'),
                ),
              ],
            ),
          ),
        ),
      );
    }

    return MaterialApp(
      title: 'Maintenance',
      theme: ThemeData(primarySwatch: Colors.blue),
      routes: {
        '/': (_) => const LoginScreen(),
        '/dashboard': (_) => const DashboardScreen(),
        '/request/new': (_) => const RequestFormScreen(),
        '/requests': (_) => const RequestsListScreen(),
        '/info': (_) => const Scaffold(
            body: Center(child: Text('Information page (placeholder)'))),
      },
    );
  }
}
