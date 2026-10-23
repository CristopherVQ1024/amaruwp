# amaruwp-back

Backend PHP del agente de WhatsApp. Slim 4 y Eloquent, misma estructura que amarumarketing-backend.

## Arranque local
    composer install
    crear el .env con DB_*, JWT_SECRET, FIREBASE_PROJECT_ID, WA_VERIFY_TOKEN, LLM_PROVIDER y las claves del proveedor
    php -S localhost:8000 -t public public/index.php

BD MySQL `AMARUWP` en el contenedor `mysql`, esquema en `db/schema.sql`. El host del `.env` es la IP del contenedor en la red docker.

## Agente
`LLM_PROVIDER` mistral u openai. Mistral usa chat completions con `MISTRAL_API_KEY` y `MISTRAL_MODEL`; OpenAI usa la API Responses con `OPENAI_API_KEY` y `OPENAI_MODEL`. Si el modelo va vacio se usa el de LlmClient.
Tools del agente: `consultar_disponibilidad` y `reservar_cita`.

## Rutas
Publicas: `GET/POST /webhook`, `POST /auth/registrar|login|google`, `GET /google/calendar/callback`, `GET /ping`.
Con JWT: `/auth/me`, `/auth/registrar-empresa`, `/auth/logout`, `/empresa`, `/productos`, `/agenda/disponibilidad`, `/agenda/slots`, `/agenda/citas`, `/google/calendar/*`, `/whatsapp/conexion`, `/chats`.
