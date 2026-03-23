import 'package:firebase_core/firebase_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:fu_uber/Core/ProviderModels/CurrentRideCreationModel.dart';
import 'package:fu_uber/Core/ProviderModels/NearbyDriversModel.dart';
import 'package:fu_uber/Core/ProviderModels/PermissionHandlerModel.dart';
import 'package:fu_uber/Core/ProviderModels/UINotifiersModel.dart';
import 'package:fu_uber/Core/ProviderModels/UserDetailsModel.dart';
import 'package:fu_uber/Core/ProviderModels/VerificationModel.dart';
import 'package:fu_uber/Core/Services/PushNotificationService.dart';
import 'package:fu_uber/Core/Services/BackgroundLocationService.dart';
import 'package:fu_uber/UI/views/EmergencyContactsScreen.dart';
import 'package:fu_uber/UI/views/LocationPermissionScreen.dart';
import 'package:fu_uber/UI/views/OnboardingScreen.dart';
import 'package:fu_uber/UI/views/ProfileScreen.dart';
import 'package:fu_uber/UI/views/SignIn.dart';
import 'package:fu_uber/UI/views/SplashScreen.dart';
import 'package:fu_uber/UI/views/RegisterScreen.dart';
import 'package:fu_uber/UI/views/EditProfileScreen.dart';
import 'package:fu_uber/UI/views/FavoritePlacesScreen.dart';
import 'package:fu_uber/UI/views/DriverHomeScreen.dart';
import 'package:fu_uber/UI/views/DriverLoginScreen.dart';
import 'package:fu_uber/UI/views/DriverRegistrationScreen.dart';
import 'package:fu_uber/UI/views/VehicleRegistrationScreen.dart';
import 'package:fu_uber/UI/views/DriverWaitingScreen.dart';
import 'package:fu_uber/UI/views/DriverStep1Screen.dart';
import 'package:fu_uber/UI/views/DriverStep2Screen.dart';
import 'package:fu_uber/UI/views/DriverStep3Screen.dart';
import 'package:fu_uber/UI/views/DriverStep4Screen.dart';
import 'package:fu_uber/UI/views/DriverStep5Screen.dart';
import 'package:fu_uber/UI/views/DriverStep6Screen.dart';
import 'package:fu_uber/UI/views/DriverTripHistoryScreen.dart';
import 'package:fu_uber/UI/views/HelpFaqScreen.dart';
import 'package:fu_uber/UI/views/OsmMapScreen.dart';
import 'package:fu_uber/UI/views/RideCompletedScreen.dart';
import 'package:fu_uber/UI/views/RideHistoryScreen.dart';
import 'package:fu_uber/UI/views/DriverProfileScreen.dart';
import 'package:fu_uber/UI/views/WelcomeScreen.dart';
import 'package:fu_uber/UI/views/PayPhoneWebViewScreen.dart';
import 'package:fu_uber/UI/views/PaymentHistoryScreen.dart';
import 'package:fu_uber/UI/views/ReceiptScreen.dart';
import 'package:fu_uber/UI/views/WalletScreen.dart';
import 'package:fu_uber/UI/views/DisputeScreen.dart';
import 'package:fu_uber/UI/views/DriverEarningsScreen.dart';
import 'package:fu_uber/UI/views/ChatScreen.dart';
import 'package:provider/provider.dart';

final GlobalKey<NavigatorState> navigatorKey = GlobalKey<NavigatorState>();

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  try {
    await Firebase.initializeApp();
    await PushNotificationService.initialize(navigatorKey);
  } catch (e) {
    print('>>> [FCM] Firebase no inicializado: $e');
  }

  // Inicializar Foreground Service para ubicación en background
  try {
    await BackgroundLocationService.initialize();
  } catch (e) {
    print('>>> [BG_SERVICE] No se pudo inicializar: $e');
  }

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
        ChangeNotifierProvider<PermissionHandlerModel>(
          create: (_) => PermissionHandlerModel(),
        ),
        ChangeNotifierProvider<VerificationModel>(
          create: (_) => VerificationModel(),
        ),
        ChangeNotifierProvider<NearbyDriversModel>(
          create: (_) => NearbyDriversModel(),
        ),
        ChangeNotifierProvider<UserDetailsModel>(
          create: (_) => UserDetailsModel(),
        ),
        ChangeNotifierProvider<CurrentRideCreationModel>(
          create: (_) => CurrentRideCreationModel(),
        ),
        ChangeNotifierProvider<UINotifiersModel>(
          create: (_) => UINotifiersModel(),
        ),
      ],
      child: MaterialApp(
        title: 'GeoMove',
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
          SplashScreen.route: (context) => SplashScreen(),
          OnboardingScreen.route: (context) => OnboardingScreen(),
          WelcomeScreen.route: (context) => WelcomeScreen(),
          SignInPage.route: (context) => SignInPage(),
          RegisterScreen.route: (context) => RegisterScreen(),
          '/driver_registration': (context) => DriverRegistrationScreen(),
          '/vehicle_registration': (context) {
            final args = ModalRoute.of(context)!.settings.arguments as Map<String, dynamic>;
            return VehicleRegistrationScreen(
              nombre: args['nombre'],
              cedula: args['cedula'],
              telefono: args['telefono'],
              password: args['password'],
            );
          },
          '/driver_waiting': (context) => DriverWaitingScreen(),
          DriverLoginScreen.route: (context) => DriverLoginScreen(),
          DriverStep1Screen.route: (context) => DriverStep1Screen(),
          DriverHomeScreen.route: (context) => DriverHomeScreen(),
          DriverProfileScreen.route: (context) => const DriverProfileScreen(),
          LocationPermissionScreen.route: (context) =>
              LocationPermissionScreen(),
          OsmMapScreen.route: (context) => OsmMapScreen(),
          ProfileScreen.route: (context) => ProfileScreen(),
          RideCompletedScreen.route: (context) => RideCompletedScreen(),
          RideHistoryScreen.route: (context) => RideHistoryScreen(),
          EditProfileScreen.route: (context) => EditProfileScreen(),
          FavoritePlacesScreen.route: (context) =>
              FavoritePlacesScreen(),
          HelpFaqScreen.route: (context) => HelpFaqScreen(),
          EmergencyContactsScreen.route: (context) =>
              EmergencyContactsScreen(),
          PayPhoneWebViewScreen.route: (context) =>
              const PayPhoneWebViewScreen(),
          PaymentHistoryScreen.route: (context) =>
              const PaymentHistoryScreen(),
          ReceiptScreen.route:        (context) => const ReceiptScreen(),
          WalletScreen.route:         (context) => const WalletScreen(),
          DisputeScreen.route:        (context) => const DisputeScreen(),
          DriverEarningsScreen.route: (context) => const DriverEarningsScreen(),
          ChatScreen.route:           (context) => const ChatScreen(),
        },
        home: SplashScreen(),
      ),
    );
  }
}
