<?php

return [
    'not_configured' => 'El asistente no está configurado: falta OPENROUTER_API_KEY en el archivo .env.',
    'unavailable' => 'El asistente no está disponible ahora mismo. Vuelve a probar en unos minutos.',
    'bad_key' => 'La clave de OpenRouter no es válida. Revisa OPENROUTER_API_KEY en el archivo .env.',
    'no_credit' => 'La cuenta de OpenRouter no tiene saldo. Recárgala para seguir usando el asistente.',
    'rate_limited' => 'Se han hecho demasiadas preguntas seguidas. Espera un momento y vuelve a probar.',
    'voice_not_configured' => 'El dictado por voz no está configurado: falta ELEVENLABS_API_KEY en el archivo .env.',
    'voice_unavailable' => 'No se ha podido transcribir el audio. Vuelve a probar o escribe el mensaje.',
    'voice_bad_key' => 'La clave de ElevenLabs no es válida. Revisa ELEVENLABS_API_KEY en el archivo .env.',
    'voice_empty' => 'No se ha entendido nada. Habla un poco más cerca del micrófono.',
    'tool_failed' => 'No se ha podido hacer: :error',
];
