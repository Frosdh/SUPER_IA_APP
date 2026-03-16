import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:latlong2/latlong.dart';

class PlaceResult {
  final String displayName;
  final String shortName;
  final double lat;
  final double lon;

  PlaceResult({
    required this.displayName,
    required this.shortName,
    required this.lat,
    required this.lon,
  });

  factory PlaceResult.fromJson(Map<String, dynamic> json) {
    final display = json['display_name'] ?? '';
    final parts = display.split(',');
    final short = parts.length >= 2
        ? '${parts[0].trim()}, ${parts[1].trim()}'
        : display;

    return PlaceResult(
      displayName: display,
      shortName: short,
      lat: double.tryParse(json['lat'] ?? '0') ?? 0.0,
      lon: double.tryParse(json['lon'] ?? '0') ?? 0.0,
    );
  }
}

class RouteResult {
  final List<LatLng> puntos;
  final double distanciaKm;
  final int duracionMin;
  final double precioEstimado;

  RouteResult({
    required this.puntos,
    required this.distanciaKm,
    required this.duracionMin,
    required this.precioEstimado,
  });
}

class OsmService {
  static const double TARIFA_BASE = 1.50;
  static const double TARIFA_POR_KM = 0.45;
  static const double TARIFA_MINIMA = 2.00;

  static Future<List<PlaceResult>> buscarLugar(String query) async {
    final texto = query.trim();
    if (texto.isEmpty) return <PlaceResult>[];

    try {
      final uriEcuador = Uri.parse(
        'https://nominatim.openstreetmap.org/search'
        '?q=${Uri.encodeComponent(texto)}'
        '&format=json'
        '&limit=5'
        '&countrycodes=ec'
        '&addressdetails=1'
        '&accept-language=es',
      );

      var resultados = await _buscarConUri(uriEcuador);
      if (resultados.isNotEmpty) return resultados;

      // Fallback sin filtro de pais.
      final uriGlobal = Uri.parse(
        'https://nominatim.openstreetmap.org/search'
        '?q=${Uri.encodeComponent(texto)}'
        '&format=json'
        '&limit=5'
        '&addressdetails=1'
        '&accept-language=es',
      );
      resultados = await _buscarConUri(uriGlobal);
      return resultados;
    } catch (e) {
      print('>>> [OSM] Error Nominatim: $e');
      return <PlaceResult>[];
    }
  }

  static Future<List<PlaceResult>> _buscarConUri(Uri uri) async {
    final response = await http
        .get(
      uri,
      headers: {
        'User-Agent': 'WendyUberApp/1.0 (contacto: soporte@wendyuber.app)',
        'Accept-Language': 'es',
      },
    )
        .timeout(const Duration(seconds: 8));

    if (response.statusCode != 200) {
      print('>>> [OSM] Nominatim HTTP ${response.statusCode}');
      return <PlaceResult>[];
    }

    final data = json.decode(response.body) as List<dynamic>;
    return data.map((item) => PlaceResult.fromJson(item as Map<String, dynamic>)).toList();
  }

  static Future<RouteResult?> calcularRuta(LatLng origen, LatLng destino) async {
    final servidores = [
      'http://router.project-osrm.org/route/v1/driving/',
      'http://routing.openstreetmap.de/routed-car/route/v1/driving/',
    ];

    for (final base in servidores) {
      try {
        final url =
            '$base'
            '${origen.longitude},${origen.latitude};'
            '${destino.longitude},${destino.latitude}'
            '?overview=full&geometries=geojson';

        final response = await http.get(Uri.parse(url)).timeout(const Duration(seconds: 12));
        if (response.statusCode != 200) {
          continue;
        }

        final data = json.decode(response.body) as Map<String, dynamic>;
        if (data['code'] != 'Ok') {
          continue;
        }

        final routes = data['routes'] as List<dynamic>?;
        if (routes == null || routes.isEmpty) {
          continue;
        }

        final route = routes[0] as Map<String, dynamic>;
        final distanciaM = (route['distance'] as num).toDouble();
        final duracionS = (route['duration'] as num).toDouble();
        final coords = route['geometry']['coordinates'] as List<dynamic>;

        final puntos = coords.map<LatLng>((c) {
          return LatLng(
            (c[1] as num).toDouble(),
            (c[0] as num).toDouble(),
          );
        }).toList();

        final distanciaKm = distanciaM / 1000;
        final duracionMin = (duracionS / 60).round();

        return RouteResult(
          puntos: puntos,
          distanciaKm: distanciaKm,
          duracionMin: duracionMin,
          precioEstimado: _calcularPrecio(distanciaKm),
        );
      } on TimeoutException {
        // Probar siguiente servidor.
      } catch (_) {
        // Probar siguiente servidor.
      }
    }

    return null;
  }

  static double _calcularPrecio(double distanciaKm) {
    final precio = TARIFA_BASE + (distanciaKm * TARIFA_POR_KM);
    return precio < TARIFA_MINIMA ? TARIFA_MINIMA : precio;
  }

  static Future<String> obtenerNombreLugar(double lat, double lon) async {
    try {
      final url = Uri.parse(
        'https://nominatim.openstreetmap.org/reverse'
        '?lat=$lat&lon=$lon&format=json&accept-language=es',
      );

      final response = await http.get(
        url,
        headers: {
          'User-Agent': 'WendyUberApp/1.0 (contacto: soporte@wendyuber.app)',
          'Accept-Language': 'es',
        },
      );

      if (response.statusCode != 200) {
        return 'Mi ubicacion';
      }

      final data = json.decode(response.body) as Map<String, dynamic>;
      final address = data['address'];
      if (address is Map<String, dynamic>) {
        final road = address['road'] as String? ?? '';
        final suburb = (address['suburb'] as String?) ?? (address['neighbourhood'] as String?) ?? '';
        if (road.isNotEmpty) {
          if (suburb.isNotEmpty) {
            return '$road, $suburb';
          }
          return road;
        }
      }
      return (data['display_name'] as String?) ?? 'Mi ubicacion';
    } catch (_) {
      return 'Mi ubicacion';
    }
  }
}
