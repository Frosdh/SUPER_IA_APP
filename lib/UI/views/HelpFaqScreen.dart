import 'package:flutter/material.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';

class HelpFaqScreen extends StatelessWidget {
  static const String route = '/help_faq';

  final List<Map<String, String>> _faqs = const [
    {
      'question': 'Como pedir un viaje',
      'answer':
          'Escribe tu destino, selecciona una categoria, revisa el precio estimado y confirma tu viaje desde el panel inferior.',
    },
    {
      'question': 'Como usar lugares favoritos',
      'answer':
          'Desde Mi perfil puedes guardar Casa y Trabajo. Luego apareceran como accesos rapidos en el mapa para pedir viajes mas rapido.',
    },
    {
      'question': 'Como funcionan los codigos de descuento',
      'answer':
          'En la confirmacion del viaje puedes ingresar un cupon. El sistema valida vigencia, monto minimo y disponibilidad antes de aplicar el descuento.',
    },
    {
      'question': 'Como compartir mi viaje',
      'answer':
          'Cuando el conductor ya fue asignado, veras el boton SHARE en el mapa. Desde ahi puedes compartir por WhatsApp, SMS o copiar el enlace.',
    },
    {
      'question': 'Como usar el boton SOS',
      'answer':
          'Durante un viaje activo, manten presionado el boton SOS para enviar tu ubicacion a tus contactos de emergencia por WhatsApp.',
    },
    {
      'question': 'Donde veo mis viajes anteriores',
      'answer':
          'En Mi perfil, entra a Mis viajes. Alli veras origen, destino, precio, distancia, duracion y calificacion de cada viaje guardado.',
    },
    {
      'question': 'Que hago si un cupon no funciona',
      'answer':
          'Verifica que el codigo este bien escrito, que no haya expirado, que cumpla el monto minimo del viaje y que no haya sido usado antes en este dispositivo.',
    },
  ];

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: ConstantColors.backgroundDark,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        leading: IconButton(
          icon: Icon(Icons.arrow_back, color: Colors.white),
          onPressed: () => Navigator.pop(context),
        ),
        title: Text(
          'Ayuda y FAQ',
          style: TextStyle(color: Colors.white, fontWeight: FontWeight.w700),
        ),
      ),
      body: ListView(
        padding: EdgeInsets.fromLTRB(20, 8, 20, 28),
        children: [
          Container(
            padding: EdgeInsets.all(18),
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: [ConstantColors.primaryViolet, ConstantColors.primaryBlue],
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
              ),
              borderRadius: BorderRadius.circular(20),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Container(
                      width: 42,
                      height: 42,
                      decoration: BoxDecoration(
                        color: Colors.white.withOpacity(0.14),
                        shape: BoxShape.circle,
                      ),
                      child: Icon(Icons.help_outline, color: Colors.white),
                    ),
                    SizedBox(width: 12),
                    Expanded(
                      child: Text(
                        'Centro de ayuda del pasajero',
                        style: TextStyle(
                          color: Colors.white,
                          fontSize: 18,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                  ],
                ),
                SizedBox(height: 12),
                Text(
                  'Aqui encuentras respuestas rapidas sobre viajes, cupones, seguridad y funciones principales de Fuber.',
                  style: TextStyle(
                    color: Colors.white.withOpacity(0.88),
                    fontSize: 13,
                    height: 1.45,
                  ),
                ),
              ],
            ),
          ),
          SizedBox(height: 18),
          ..._faqs.map((faq) => _faqCard(
                question: faq['question'],
                answer: faq['answer'],
              )),
          SizedBox(height: 18),
          Container(
            padding: EdgeInsets.all(18),
            decoration: BoxDecoration(
              color: ConstantColors.backgroundCard,
              borderRadius: BorderRadius.circular(18),
              border: Border.all(color: ConstantColors.borderColor),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Icon(Icons.support_agent, color: ConstantColors.primaryViolet),
                    SizedBox(width: 10),
                    Text(
                      'Soporte',
                      style: TextStyle(
                        color: ConstantColors.textWhite,
                        fontSize: 15,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ],
                ),
                SizedBox(height: 12),
                Text(
                  'Si un problema no aparece aqui, revisa tu conexion, vuelve a intentar la accion y luego reporta el caso con capturas y la hora del incidente.',
                  style: TextStyle(
                    color: ConstantColors.textGrey,
                    fontSize: 13,
                    height: 1.45,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _faqCard({String question, String answer}) {
    return Container(
      margin: EdgeInsets.only(bottom: 12),
      decoration: BoxDecoration(
        color: ConstantColors.backgroundCard,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: ConstantColors.borderColor),
      ),
      child: Theme(
        data: ThemeData.dark().copyWith(
          dividerColor: Colors.transparent,
          splashColor: Colors.transparent,
          highlightColor: Colors.transparent,
        ),
        child: ExpansionTile(
          tilePadding: EdgeInsets.symmetric(horizontal: 16, vertical: 6),
          childrenPadding: EdgeInsets.fromLTRB(16, 0, 16, 18),
          title: Text(
            question,
            style: TextStyle(
              color: ConstantColors.textWhite,
              fontSize: 14,
              fontWeight: FontWeight.w600,
            ),
          ),
          children: [
            Align(
              alignment: Alignment.centerLeft,
              child: Text(
                answer,
                style: TextStyle(
                  color: ConstantColors.textGrey,
                  fontSize: 13,
                  height: 1.5,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
