import 'package:flutter/cupertino.dart';
import 'package:fu_uber/Core/Models/UserDetails.dart';
import 'package:fu_uber/Core/Models/UserPlaces.dart';
import 'package:fu_uber/Core/Preferences/AuthPrefs.dart';
import 'package:fu_uber/Core/Repository/Repository.dart';

class UserDetailsModel extends ChangeNotifier {
  late String uuid;
  late String photoUrl;
  late String name;
  late String email;
  late String phone;
  String? ongoingRide;
  late List<String> previousRides;
  late List<UserPlaces> favouritePlaces;

  UserDetailsModel() {
    uuid = '';
    photoUrl = '';
    name = '';
    email = '';
    phone = '';
    ongoingRide = null;
    previousRides = <String>[];
    favouritePlaces = <UserPlaces>[];
    _loadUserData();
  }

  void _loadUserData() async {
    await reload();
  }

  Future<void> reload() async {
    name  = await AuthPrefs.getUserName();
    email = await AuthPrefs.getUserEmail();
    phone = await AuthPrefs.getUserPhone();
    notifyListeners();
  }

  void setStaticData(UserDetails userDetails) {}

  void changeName(String newName) {}

  void addToFavouritePlace(UserPlaces userPlaces) {
    if (favouritePlaces.length >= 5) {
      favouritePlaces.insert(0, userPlaces);
      favouritePlaces.removeLast();
    } else {
      favouritePlaces.add(userPlaces);
    }
    Repository.addFavPlacesToDataBase(favouritePlaces);
    notifyListeners();
  }
}
