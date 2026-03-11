import 'package:flutter/cupertino.dart';
import 'package:fu_uber/Core/Models/UserDetails.dart';
import 'package:fu_uber/Core/Models/UserPlaces.dart';
import 'package:fu_uber/Core/Preferences/AuthPrefs.dart';
import 'package:fu_uber/Core/Repository/Repository.dart';

class UserDetailsModel extends ChangeNotifier {
  String uuid;
  String photoUrl;
  String name;
  String email;
  String phone;
  String ongoingRide;
  List<String> previousRides;
  List<UserPlaces> favouritePlaces;

  UserDetailsModel() {
    uuid = '';
    photoUrl = '';
    name = '';
    email = '';
    phone = '';
    ongoingRide = null;
    previousRides = [];
    favouritePlaces = [];
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

  setStaticData(UserDetails userDetails) {}

  changeName(String newName) {}

  addToFavouritePlace(UserPlaces userPlaces) {
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
