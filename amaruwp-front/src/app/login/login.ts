import { ChangeDetectionStrategy, Component, ElementRef, inject, signal, viewChild } from '@angular/core';
import { Router } from '@angular/router';
import { Auth } from '../core/auth';
import { Icono } from '../core/icono';

interface Tarjeta {
  icon: string;
  title: string;
  desc: string;
  clase: string;
  tono?: 'success' | 'warn';
}

@Component({
  selector: 'app-login',
  imports: [Icono],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './login.html',
  styleUrl: './login.scss',
})
export class Login {
  private auth = inject(Auth);
  private router = inject(Router);

  private carrusel = viewChild<ElementRef<HTMLElement>>('carousel');

  cargando = signal(false);
  error = signal('');
  puntoActivo = signal(0);

  features: Tarjeta[] = [
    { icon: 'leaf', title: 'Todo en una plataforma', desc: 'Agente de WhatsApp, productos y agenda en un solo lugar.', clase: 'hero' },
    { icon: 'message-circle', title: 'Agente de WhatsApp', desc: 'Responde a tus clientes automáticamente 24/7.', clase: '' },
    { icon: 'calendar-check', title: 'Reserva de citas', desc: 'Tus clientes agendan solos, sin cruces de horario.', clase: '', tono: 'success' },
    { icon: 'package', title: 'Productos y servicios', desc: 'El agente conoce tu catálogo, precios y preguntas frecuentes.', clase: '', tono: 'success' },
    { icon: 'calendar-clock', title: 'Google Calendar', desc: 'Tu disponibilidad y tus citas sincronizadas con tu calendario.', clase: '', tono: 'warn' },
    { icon: 'sparkles', title: 'IA que entiende tu negocio', desc: 'Respuestas con el tono y la información de tu empresa.', clase: '' },
  ];

  mosaico: Tarjeta[] = [
    { icon: 'message-circle', title: 'Agente de WhatsApp', desc: 'Responde a tus clientes automáticamente 24/7.', clase: 'c1' },
    { icon: 'zap', title: 'Respuestas inmediatas', desc: 'Tus clientes no esperan para recibir información.', clase: 'c2', tono: 'success' },
    { icon: 'sparkles', title: 'IA que entiende tu negocio', desc: 'Respuestas con el tono y la información de tu empresa.', clase: 'c3' },
    { icon: 'package', title: 'Productos y servicios', desc: 'Catálogo con fotos, precios y preguntas frecuentes.', clase: 'c4', tono: 'warn' },
    { icon: 'leaf', title: 'Todo en una plataforma', desc: 'Agente, catálogo y agenda en un solo sistema.', clase: 'c5' },
    { icon: 'calendar-clock', title: 'Google Calendar', desc: 'Disponibilidad y citas sincronizadas.', clase: 'c6' },
    { icon: 'smartphone', title: 'Desde tu celular', desc: 'Gestiona tu negocio desde cualquier lugar.', clase: 'c7' },
    { icon: 'calendar-check', title: 'Reserva de citas', desc: 'Horarios libres revisados antes de confirmar.', clase: 'c8', tono: 'success' },
    { icon: 'layers', title: 'Fotos y detalles', desc: 'Varias fotos por producto para vender mejor.', clase: 'c9' },
    { icon: 'clock', title: 'Slots a tu medida', desc: 'Define la duración de cada cita en minutos.', clase: 'c10' },
    { icon: 'headset', title: 'Atención sin pausa', desc: 'Tu negocio responde incluso fuera de horario.', clase: 'c11' },
    { icon: 'rocket', title: 'Más ventas', desc: 'Convierte conversaciones en citas y pedidos.', clase: 'c12' },
  ];

  alDesplazar(): void {
    const pista = this.carrusel()?.nativeElement;

    if (!pista) return;

    let cercano = 0;
    let minimo = Infinity;

    pista.querySelectorAll<HTMLElement>('.feat-card').forEach((tarjeta, i) => {
      const distancia = Math.abs(tarjeta.offsetLeft - pista.scrollLeft);

      if (distancia < minimo) {
        minimo = distancia;
        cercano = i;
      }
    });

    this.puntoActivo.set(cercano);
  }

  inclinar(e: MouseEvent): void {
    if (!window.matchMedia('(min-width: 1025px) and (hover: hover) and (pointer: fine)').matches) return;

    const tarjeta = e.currentTarget as HTMLElement;
    const r = tarjeta.getBoundingClientRect();
    const x = e.clientX - r.left;
    const y = e.clientY - r.top;
    const max = 12;

    requestAnimationFrame(() => {
      tarjeta.style.setProperty('--mx', (x / r.width) * 100 + '%');
      tarjeta.style.setProperty('--my', (y / r.height) * 100 + '%');
      tarjeta.style.setProperty('--rx', -((y / r.height) - 0.5) * max * 2 + 'deg');
      tarjeta.style.setProperty('--ry', ((x / r.width) - 0.5) * max * 2 + 'deg');
    });
  }

  soltar(e: MouseEvent): void {
    const tarjeta = e.currentTarget as HTMLElement;

    requestAnimationFrame(() => {
      tarjeta.style.setProperty('--rx', '0deg');
      tarjeta.style.setProperty('--ry', '0deg');
      tarjeta.style.setProperty('--mx', '50%');
      tarjeta.style.setProperty('--my', '50%');
    });
  }

  async entrarConGoogle(): Promise<void> {
    this.cargando.set(true);
    this.error.set('');

    try {
      const usuario = await this.auth.entrarConGoogle();
      await this.router.navigate([usuario.id_empresa ? '/inicio' : '/registrar-empresa']);
    } catch (e: any) {
      const cancelado = e?.code === 'auth/popup-closed-by-user' || e?.code === 'auth/cancelled-popup-request';

      this.error.set(cancelado ? '' : e?.error?.error ?? e?.message ?? 'No se pudo iniciar sesión con Google');
    } finally {
      this.cargando.set(false);
    }
  }
}
