import 'package:latlong2/latlong.dart';

class Driver {
  late String driverName;
  late String driverImage;
  late double driverRating;
  late String driverId;
  late CarDetail carDetail;
  late LatLng currentLocation;

  Driver(this.driverName, this.driverImage, this.driverRating, this.driverId,
      this.carDetail, this.currentLocation);
}

class CarDetail {
  late String carId;
  late String carCompanyName;
  late String carModel;
  late String carNumber;

  CarDetail(this.carId, this.carCompanyName, this.carModel, this.carNumber);
}
