import 'package:flutter/widgets.dart';
import 'package:sliding_up_panel/sliding_up_panel.dart';

class UINotifiersModel extends ChangeNotifier {
  double originDestinationVisibility = 1;
  bool isPanelOpen = false;
  bool isPanelScrollDisabled = true;
  bool searchingRide = false;
  final ScrollController sheetScrollController = ScrollController();
  final PanelController panelController = PanelController();

  UINotifiersModel() {
    sheetScrollController.addListener(() {
      if (sheetScrollController.offset <= 1.0) {
        disablePanelScroll();
      } else
        enablePanelScroll();
    });
  }

  void setOriginDestinationVisibility(double visibility) {
    visibility = (visibility - 1);
    if (visibility < 0) visibility *= -1;
    originDestinationVisibility = visibility;
    notifyListeners();
  }

  void disablePanelScroll() {
    isPanelScrollDisabled = true;
    isPanelOpen = false;
    notifyListeners();
  }

  void enablePanelScroll() {
    isPanelScrollDisabled = false;
    notifyListeners();
  }

  void onPanelOpen() {
    isPanelOpen = true;
    notifyListeners();
  }

  void onPanelClosed() {
    isPanelOpen = false;
    notifyListeners();
  }

  void searchingRideNotify() {
    //closes the panel
    panelController.close();
    // it hides the panel, so that we wont be able to use it for time
    if (!searchingRide)
      panelController.hide();
    else
      panelController.show();
    searchingRide = !searchingRide;
    notifyListeners();
  }
}
