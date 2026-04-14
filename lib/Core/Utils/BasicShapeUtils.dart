import 'package:flutter/cupertino.dart';
import 'package:flutter/material.dart';

class ShapeUtils {
  static const BorderRadiusGeometry borderRadiusGeometry = BorderRadius.only(
    topLeft: Radius.circular(24.0),
    topRight: Radius.circular(24.0),
  );
  static const RoundedRectangleBorder selectedCardShape = RoundedRectangleBorder(
      side: BorderSide(color: Colors.grey, width: 2.0),
      borderRadius: BorderRadius.all(Radius.circular(4.0)));
  static const RoundedRectangleBorder notSelectedCardShape = RoundedRectangleBorder(
      side: BorderSide(color: Colors.white, width: 2.0),
      borderRadius: BorderRadius.all(Radius.circular(4.0)));

  static const RoundedRectangleBorder rounderCard = RoundedRectangleBorder(
      borderRadius: BorderRadius.all(Radius.circular(10.0)));
}
