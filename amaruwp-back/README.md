# amaruwp-back

Backend PHP del agente de WhatsApp. Slim 4 y Eloquent, misma estructura que amarumarketing-backend.

## Arranque local
    composer install
    cp .env.example .env     # completar JWT_SECRET, WA_VERIFY_TOKEN, LLM_API_KEY
    php -S localhost:8000 -t public public/index.php

BD MySQL `AMARUWP` en el contenedor `mysql`, esquema en `db/schema.sql`. El host del `.env` es la IP del contenedor en la red docker.

## Agente
`LLM_PROVIDER` mistral u openai. Ambos usan chat completions con tools, solo cambian `LLM_BASE_URL`, `LLM_MODEL` y `LLM_API_KEY`.
Tools del agente: `consultar_disponibilidad` y `reservar_cita`.

## Rutas
Publicas: `GET/POST /webhook`, `POST /auth/registrar|login|google`, `GET /google/calendar/callback`, `GET /ping`.
Con JWT: `/auth/me`, `/auth/registrar-empresa`, `/auth/logout`, `/empresa`, `/productos`, `/agenda/disponibilidad`, `/agenda/slots`, `/agenda/citas`, `/google/calendar/*`, `/whatsapp/conexion`, `/chats`.
