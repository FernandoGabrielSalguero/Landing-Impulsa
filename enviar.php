<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nombre = $_POST['nombre'];
    $telefono = $_POST['telefono'];
    $email = $_POST['email'];
    $mensaje = $_POST['mensaje'];

    $para = "info@impulsagroup.com";
    $asunto = "Nuevo mensaje desde tu web";
    $contenido = "Nombre: $nombre\nTeléfono: $telefono\nCorreo: $email\nMensaje:\n$mensaje";
    $headers = "From: $email";

    if (mail($para, $asunto, $contenido, $headers)) {
        header("Location: index.html");
    } else {
        echo "Error al enviar el mensaje.";
    }
}
