<?php
// ============================================================
// payment_cancel.php
// URL de retorno CANCELADO de PayPhone.
// La app intercepta esta URL en el WebView — esta página
// nunca se ve visualmente, pero debe existir y responder 200.
// ============================================================
header("Content-Type: text/html; charset=utf-8");
?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Pago cancelado</title></head>
<body style="font-family:sans-serif;text-align:center;padding:40px;background:#0D0D1A;color:white;">
  <h2>❌ Pago cancelado</h2>
  <p>Regresando a Super_IA...</p>
</body>
</html>
