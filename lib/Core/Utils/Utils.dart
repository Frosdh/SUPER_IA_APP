import 'dart:typed_data';
import 'dart:ui';

import 'package:flutter/services.dart';
import 'package:latlong2/latlong.dart';

class Utils {
  // !DECODE POLY
  static List<double> decodePoly(String poly) {
    final list = poly.codeUnits;
    final lList = <double>[];
    var index = 0;
    final len = poly.length;
    var c = 0;
    do {
      var shift = 0;
      int result = 0;
      do {
        c = list[index] - 63;
        result |= (c & 0x1F) << (shift * 5);
        index++;
        shift++;
      } while (c >= 32);
      if (result & 1 == 1) {
        result = ~result;
      }
      final result1 = (result >> 1) * 0.00001;
      lList.add(result1.toDouble());
    } while (index < len);

    for (var i = 2; i < lList.length; i++) lList[i] += lList[i - 2];

    return lList;
  }

  static List<LatLng> convertToLatLng(List<double> points) {
    final result = <LatLng>[];
    for (var i = 0; i < points.length; i++) {
      if (i % 2 != 0) {
        result.add(LatLng(points[i - 1], points[i]));
      }
    }
    return result;
  }

  static Future<Uint8List> getBytesFromAsset(String path, int width) async {
    final data = await rootBundle.load(path);
    final codec = await instantiateImageCodec(data.buffer.asUint8List(),
        targetWidth: width);
    final fi = await codec.getNextFrame();
    return (await fi.image.toByteData(format: ImageByteFormat.png))!
        .buffer
        .asUint8List();
  }
}
