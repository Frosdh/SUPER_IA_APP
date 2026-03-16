import 'package:fu_uber/Core/Models/UserPlaces.dart';

class UserDetails {
  late String uuid;
  late String photoUrl;
  late String name;
  late String email;
  late String phone;
  String? ongoingRide;
  late List<String> previousRides;
  late List<UserPlaces> favouritePlaces;

  UserDetails(this.uuid, this.photoUrl, this.name, this.email, this.phone,
      this.ongoingRide,
      this.previousRides, this.favouritePlaces);
}
