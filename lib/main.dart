import 'package:firebase_core/firebase_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:super_ia/Core/Constants/colorConstants.dart';
import 'package:super_ia/Core/Services/BackgroundLocationService.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:super_ia/Core/ProviderModels/VerificationModel.dart';
import 'package:super_ia/Core/ProviderModels/PermissionHandlerModel.dart';
import 'package:super_ia/UI/views/SplashScreen.dart';
import 'package:super_ia/UI/views/OnboardingScreen.dart';
import 'package:super_ia/UI/views/WelcomeScreen.dart';
import 'package:super_ia/UI/views/SignIn.dart';
import 'package:super_ia/UI/views/RegisterScreen.dart';
import 'package:super_ia/UI/views/AsesorRegistrationScreen.dart';
import 'package:super_ia/UI/views/LocationPermissionScreen.dart';
import 'package:super_ia/UI/views/OsmMapScreen.dart';
import 'package:super_ia/UI/views/DriverHomeScreen.dart';
import 'package:super_ia/UI/views/DriverLoginScreen.dart';
import 'package:super_ia/UI/views/ProfileScreen.dart';
import 'package:super_ia/UI/views/NuevaEncuestaScreen.dart';
import 'package:super_ia/UI/views/RecuperarPasswordScreen.dart';
import 'package:super_ia/UI/views/VerificarOtpScreen.dart';
import 'package:super_ia/UI/views/NuevaPasswordScreen.dart';
import 'package:provider/provider.dart';

final GlobalKey<NavigatorState> navigatorKey = GlobalKey<NavigatorState>();

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  try {
    await Firebase.initializeApp();
  } catch (e) {
    print('>>> Firebase no inicializado: $e');
  }

  // Inicializar el servicio de rastreo en segundo plano (obligatorio antes de startService).
  BackgroundLocationService.initialize();

  SystemChrome.setSystemUIOverlayStyle(
    SystemUiOverlayStyle(
      statusBarColor: Colors.transparent,
      statusBarIconBrightness: Brightness.light,
      systemNavigationBarColor: ConstantColors.backgroundDark,
      systemNavigationBarIconBrightness: Brightness.light,
    ),
  );

  SystemChrome.setPreferredOrientations([
    DeviceOrientation.portraitUp,
    DeviceOrientation.portraitDown,
  ]);

  runApp(const MyApp());
}

class MyApp extends StatelessWidget {
  static const String TAG = "MyApp";

  const MyApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MultiProvider(
      providers: [
        ChangeNotifierProvider<VerificationModel>(
          create: (_) => VerificationModel(),
        ),
        ChangeNotifierProvider<PermissionHandlerModel>(
          create: (_) => PermissionHandlerModel(),
        ),
      ],
      child: MaterialApp(
        title: 'SUPER_IA - Gestión Comercial',
        debugShowCheckedModeBanner: false,
        theme: ThemeData(
          brightness: Brightness.dark,
          primaryColor: ConstantColors.primaryViolet,
          scaffoldBackgroundColor: ConstantColors.backgroundDark,
          fontFamily: GoogleFonts.poppins().fontFamily,
          colorScheme: ColorScheme.dark(
            primary: ConstantColors.primaryViolet,
            secondary: ConstantColors.primaryBlue,
            background: ConstantColors.backgroundDark,
            surface: ConstantColors.backgroundCard,
          ),
          textTheme: TextTheme(
            bodyLarge: GoogleFonts.poppins(color: ConstantColors.textWhite),
            bodyMedium: GoogleFonts.poppins(color: ConstantColors.textGrey),
            titleLarge: GoogleFonts.poppins(
              color: ConstantColors.textWhite,
              fontWeight: FontWeight.w700,
              letterSpacing: -0.5,
            ),
            titleMedium: GoogleFonts.poppins(color: ConstantColors.textWhite),
            titleSmall: GoogleFonts.poppins(
                color: ConstantColors.textGrey, fontSize: 12),
            bodySmall: GoogleFonts.poppins(
                color: ConstantColors.textSubtle, fontSize: 12),
          ),
          appBarTheme: AppBarTheme(
            color: Colors.transparent,
            elevation: 0,
            iconTheme: const IconThemeData(color: Colors.white),
            titleTextStyle: GoogleFonts.poppins(
              color: Colors.white,
              fontSize: 18,
              fontWeight: FontWeight.w700,
            ),
          ),
          inputDecorationTheme: InputDecorationTheme(
            filled: true,
            fillColor: ConstantColors.backgroundCard,
            border: OutlineInputBorder(
              borderRadius: BorderRadius.circular(14),
              borderSide: BorderSide(color: ConstantColors.borderColor),
            ),
            enabledBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(14),
              borderSide: BorderSide(color: ConstantColors.borderColor),
            ),
            focusedBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(14),
              borderSide:
                  BorderSide(color: ConstantColors.primaryViolet, width: 1.5),
            ),
            hintStyle: GoogleFonts.poppins(color: ConstantColors.textSubtle),
            labelStyle: GoogleFonts.poppins(color: ConstantColors.textGrey),
          ),
        ),
        navigatorKey: navigatorKey,
        routes: {
          '/splash': (context) => SplashScreen(),
          '/onboarding': (context) => OnboardingScreen(),
          '/welcome': (context) => WelcomeScreen(),
          '/signin': (context) => SignInPage(),
          '/register': (context) => RegisterScreen(),
          '/asesor-registration': (context) => AsesorRegistrationScreen(),
          DriverLoginScreen.route: (context) => DriverLoginScreen(),
          DriverHomeScreen.route: (context) => DriverHomeScreen(),
          LocationPermissionScreen.route: (context) => LocationPermissionScreen(),
          OsmMapScreen.route: (context) => OsmMapScreen(),
          ProfileScreen.route: (context) => ProfileScreen(),
          RecuperarPasswordScreen.route: (context) => const RecuperarPasswordScreen(),
          VerificarOtpScreen.route: (context) => const VerificarOtpScreen(),
          NuevaPasswordScreen.route: (context) => const NuevaPasswordScreen(),
          '/nueva-encuesta': (context) {
            final args = ModalRoute.of(context)?.settings.arguments as Map<String, dynamic>?;
            return NuevaEncuestaScreen(tipoTarea: args?['tipoTarea'] ?? 'prospecto_nuevo');
          },
        },
        home: SplashScreen(),
      ),
    );
  }
}
