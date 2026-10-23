import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { environment } from '../../environments/environment';

export interface ConexionWhatsapp {
  waba_id: string | null;
  phone_number_id: string;
  numero: string | null;
  negocio: string | null;
  activo: boolean;
  verificado: boolean;
  coexistence: boolean;
}

declare global {
  interface Window {
    FB?: any;
    fbAsyncInit?: () => void;
  }
}

const VERSION_GRAPH = 'v21.0';

@Injectable({ providedIn: 'root' })
export class Whatsapp {
  private http = inject(HttpClient);
  private api = environment.apiUrl;

  conexion = signal<ConexionWhatsapp | null>(null);
  cargado = signal(false);
  listo = signal(false);
  trabajando = signal(false);
  error = signal('');
  aviso = signal('');

  conectado = computed(() => !!this.conexion()?.activo);

  private configId = '';
  private esCoexistence = false;
  private escuchando = false;

  async cargar(): Promise<void> {
    const res = await firstValueFrom(this.http.get<{ conexion: ConexionWhatsapp | null }>(`${this.api}/whatsapp/conexion`));

    this.conexion.set(res.conexion);
    this.cargado.set(true);
  }

  // carga el SDK de Facebook con el app id del back para que el login abra sin demora
  async preparar(): Promise<void> {
    this.escucharRegistro();

    const { app_id, config_id } = await firstValueFrom(
      this.http.get<{ app_id: string; config_id: string }>(`${this.api}/whatsapp/start-process`),
    );

    if (!app_id || !config_id) {
      throw new Error('Faltan WA_APP_ID y WA_CONFIG_ID en el .env del back');
    }

    this.configId = config_id;

    if (window.FB) {
      window.FB.init({ appId: app_id, version: VERSION_GRAPH });
      this.listo.set(true);
      return;
    }

    window.fbAsyncInit = () => {
      window.FB.init({ appId: app_id, version: VERSION_GRAPH });
      this.listo.set(true);
    };

    const script = document.createElement('script');
    script.src = 'https://connect.facebook.net/en_US/sdk.js';
    script.async = true;
    script.defer = true;
    script.onerror = () => this.error.set('No se pudo cargar el SDK de Facebook');
    document.body.appendChild(script);
  }

  conectar(): void {
    if (!window.FB || !this.listo()) return;

    this.error.set('');
    this.esCoexistence = false;

    window.FB.login(
      (respuesta: any) => {
        if (respuesta.authResponse?.code) {
          this.guardar(respuesta.authResponse.code, this.esCoexistence);
        }
      },
      {
        config_id: this.configId,
        response_type: 'code',
        override_default_response_type: true,
        extras: { setup: {}, featureType: 'whatsapp_business_app_onboarding', sessionInfoVersion: '3' },
      },
    );
  }

  async desconectar(): Promise<void> {
    this.trabajando.set(true);
    this.error.set('');

    try {
      await firstValueFrom(this.http.post(`${this.api}/whatsapp/desconectar`, {}));
      this.aviso.set('WhatsApp Business desconectado');
      await this.cargar();
      await this.preparar();
    } catch (e: any) {
      this.error.set(e?.error?.error ?? 'Error al desconectar');
    } finally {
      this.trabajando.set(false);
    }
  }

  private async guardar(code: string, esCoexistence: boolean): Promise<void> {
    this.trabajando.set(true);

    try {
      await firstValueFrom(this.http.post(`${this.api}/whatsapp/save`, { code, is_coexistence: esCoexistence }));
      this.aviso.set('WhatsApp Business conectado');
      await this.cargar();
    } catch (e: any) {
      this.error.set(e?.error?.error ?? 'Error al guardar');
    } finally {
      this.trabajando.set(false);
    }
  }

  // el registro de Meta avisa por mensaje si el numero viene de la app WhatsApp Business
  private escucharRegistro(): void {
    if (this.escuchando) return;

    this.escuchando = true;

    window.addEventListener('message', (event) => {
      if (!event.origin.endsWith('facebook.com')) return;

      try {
        const data = JSON.parse(event.data);

        if (data.type === 'WA_EMBEDDED_SIGNUP') {
          this.esCoexistence = data.event === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING';
        }
      } catch {
        // mensajes que no son JSON se ignoran
      }
    });
  }
}
