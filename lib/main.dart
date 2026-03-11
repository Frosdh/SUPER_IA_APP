import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/ProviderModels/CurrentRideCreationModel.dart';
import 'package:fu_uber/Core/ProviderModels/MapModel.dart';
import 'package:fu_uber/Core/ProviderModels/NearbyDriversModel.dart';
import 'package:fu_uber/Core/ProviderModels/PermissionHandlerModel.dart';
import 'package:fu_uber/Core/ProviderModels/RideBookedModel.dart';
import 'package:fu_uber/Core/ProviderModels/UINotifiersModel.dart';
import 'package:fu_uber/Core/ProviderModels/UserDetailsModel.dart';
import 'package:fu_uber/Core/ProviderModels/VerificationModel.dart';
import 'package:fu_uber/UI/views/EmergencyContactsScreen.dart';
import 'package:fu_uber/UI/views/LocationPermissionScreen.dart';
import 'package:fu_uber/UI/views/MainScreen.dart';
import 'package:fu_uber/UI/views/OnboardingScreen.dart';
import 'package:fu_uber/UI/views/OnGoingRideScreen.dart';
import 'package:fu_uber/UI/views/ProfileScreen.dart';
import 'package:fu_uber/UI/views/SignIn.dart';
import 'package:fu_uber/UI/views/SplashScreen.dart';
import 'package:fu_uber/UI/views/RegisterScreen.dart';
import 'package:fu_uber/UI/views/EditProfileScreen.dart';
import 'package:fu_uber/UI/views/FavoritePlacesScreen.dart';
import 'package:fu_uber/UI/views/OsmMapScreen.dart';
import 'package:fu_uber/UI/views/RideCompletedScreen.dart';
import 'package:fu_uber/UI/views/RideHistoryScreen.dart';
import 'package:provider/provider.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();

  // Barra de estado transparente sobre fondo oscuro
  SystemChrome.setSystemUIOverlayStyle(
    SystemUiOverlayStyle(
      statusBarColor: Colors.transparent,
      statusBarIconBrightness: Brightness.light,
      systemNavigationBarColor: ConstantColors.backgroundDark,
      systemNavigationBarIconBrightness: Brightness.light,
    ),
  );

  // Solo orientación vertical
  SystemChrome.setPreferredOrientations([
    DeviceOrientation.portraitUp,
    DeviceOrientation.portraitDown,
  ]);

  runApp(MyApp());
}

final GlobalKey<NavigatorState> navigatorKey = GlobalKey<NavigatorState>();

class MyApp extends StatelessWidget {
  static const String TAG = "MyApp";

  @override
  Widget build(BuildContext context) {
    return MultiProvider(
      providers: [
        ChangeNotifierProvider<PermissionHandlerModel>(
          builder: (context) => PermissionHandlerModel(),
        ),
        ChangeNotifierProvider<MapModel>(
          builder: (context) => MapModel(),
        ),
        ChangeNotifierProxyProvider<MapModel, RideBookedModel>(
            initialBuilder: (_) => RideBookedModel(),
            builder: (_, foo, bar) {
              bar.originLatLng = foo.pickupPosition;
              bar.destinationLatLng = foo.destinationPosition;
              return bar;
            }),
        ChangeNotifierProvider<VerificationModel>(
          builder: (context) => VerificationModel(),
        ),
        ChangeNotifierProvider<NearbyDriversModel>(
          builder: (context) => NearbyDriversModel(),
        ),
        ChangeNotifierProvider<UserDetailsModel>(
          builder: (context) => UserDetailsModel(),
        ),
        ChangeNotifierProvider<CurrentRideCreationModel>(
          builder: (context) => CurrentRideCreationModel(),
        ),
        ChangeNotifierProvider<UINotifiersModel>(
          builder: (context) => UINotifiersModel(),
        ),
      ],
      child: MaterialApp(
        title: 'Wendy Uber',
        debugShowCheckedModeBanner: false,
        theme: ThemeData(
          brightness: Brightness.dark,
          primaryColor: ConstantColors.primaryViolet,
          scaffoldBackgroundColor: ConstantColors.backgroundDark,
          fontFamily: 'Roboto',
          colorScheme: ColorScheme.dark(
            primary: ConstantColors.primaryViolet,
            secondary: ConstantColors.primaryBlue,
            background: ConstantColors.backgroundDark,
            surface: ConstantColors.backgroundCard,
          ),
          textTheme: TextTheme(
            bodyText1: TextStyle(color: ConstantColors.textWhite),
            bodyText2: TextStyle(color: ConstantColors.textGrey),
          ),
        ),
        navigatorKey: navigatorKey,
        routes: {
          SplashScreen.route: (context) => SplashScreen(),
          OnboardingScreen.route: (context) => OnboardingScreen(),
          SignInPage.route: (context) => SignInPage(),
          RegisterScreen.route: (context) => RegisterScreen(),
          LocationPermissionScreen.route: (context) =>
              LocationPermissionScreen(),
          OsmMapScreen.route: (context) => OsmMapScreen(),
          MainScreen.route: (context) => MainScreen(),
          ProfileScreen.route: (context) => ProfileScreen(),
          OnGoingRideScreen.route: (context) => OnGoingRideScreen(),
          RideCompletedScreen.route: (context) => RideCompletedScreen(),
          RideHistoryScreen.route: (context) => RideHistoryScreen(),
          EditProfileScreen.route: (context) => EditProfileScreen(),
          FavoritePlacesScreen.route: (context) => FavoritePlacesScreen(),
          EmergencyContactsScreen.route: (context) => EmergencyContactsScreen(),
        },
        home: SplashScreen(),
      ),
    );
  }
}
