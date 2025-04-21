<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    date_default_timezone_set('America/Argentina/Buenos_Aires'); // Cambiá según tu zona
    $fechaHora = date('l, F j, Y g:i A');

    $nombre = $_POST['nombre'];
    $telefono = $_POST['telefono'];
    $email = $_POST['email'];
    $mensaje = $_POST['mensaje'];

    $para = "info@impulsagroup.com";
    $asunto = "Nuevo mensaje desde la landing page de Impulsa Group";

    $contenido = "
    <html>
    <head>
      <meta charset='UTF-8'>
      <style>
        body { font-family: Arial, sans-serif; }
        .label { font-weight: bold; margin-top: 10px; }
        .valor { margin-bottom: 10px; }
        .footer { margin-top: 30px; font-size: 12px; color: #666; }
      </style>
    </head>
    <body>
      <h2>Nuevo mensaje recibido:</h2>
      <p><span class='label'>Nombre:</span><br>$nombre</p>
      <p><span class='label'>Teléfono:</span><br>$telefono</p>
      <p><span class='label'>Correo:</span><br>$email</p>
      <p><span class='label'>Mensaje:</span><br>$mensaje</p>

      <div class='footer'>
        Enviado el $fechaHora (hora local)
      </div>
    </body>
    </html>
  ";

    $headers  = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: <$email>" . "\r\n";

    if (mail($para, $asunto, $contenido, $headers)) {
        header("Location: gracias.html");
        exit();
    } else {
        echo "Error al enviar el mensaje.";
    }
}
