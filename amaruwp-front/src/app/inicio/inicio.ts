import { ChangeDetectionStrategy, Component, effect, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Auth } from '../core/auth';
import { Icono } from '../core/icono';
import { Whatsapp } from '../core/whatsapp';

@Component({
  selector: 'app-inicio',
  imports: [Icono, RouterLink],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './inicio.html',
  styleUrl: './inicio.scss',
})
export class Inicio {
  auth = inject(Auth);
  wa = inject(Whatsapp);

  private temporizador?: ReturnType<typeof setTimeout>;

  constructor() {
    this.iniciar();

    effect(() => {
      if (!this.wa.aviso()) return;

      clearTimeout(this.temporizador);
      this.temporizador = setTimeout(() => this.wa.aviso.set(''), 3500);
    });
  }

  private async iniciar(): Promise<void> {
    try {
      await this.wa.cargar();

      if (!this.wa.conectado()) await this.wa.preparar();
    } catch (e: any) {
      this.wa.error.set(e?.message ?? 'No se pudo cargar la conexión de WhatsApp');
    }
  }

  desconectar(): void {
    if (confirm('¿Desconectar WhatsApp Business? El agente dejará de responder.')) this.wa.desconectar();
  }
}
