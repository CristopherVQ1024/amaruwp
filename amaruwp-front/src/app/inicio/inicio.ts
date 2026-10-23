import { ChangeDetectionStrategy, Component, effect, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { HorarioEditor } from '../agenda/horario-editor';
import { Agenda, EstadoGoogle, GoogleCalendar, Horario, aVentanas, deVentanas, horarioVacio, validarHorario } from '../core/agenda';
import { Auth } from '../core/auth';
import { Icono } from '../core/icono';
import { Whatsapp } from '../core/whatsapp';

@Component({
  selector: 'app-inicio',
  imports: [Icono, RouterLink, HorarioEditor],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './inicio.html',
  styleUrl: './inicio.scss',
})
export class Inicio {
  auth = inject(Auth);
  wa = inject(Whatsapp);
  private agenda = inject(Agenda);
  private google = inject(GoogleCalendar);

  horario = signal<Horario>(horarioVacio());
  horarioGuardado = signal(JSON.stringify(horarioVacio()));
  guardandoHorario = signal(false);
  estadoGoogle = signal<EstadoGoogle | null>(null);
  trabajandoGoogle = signal(false);
  errorAgenda = signal('');

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
    this.cargarAgenda();

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

  hayCambios(): boolean {
    return JSON.stringify(this.horario()) !== this.horarioGuardado();
  }

  private async cargarAgenda(): Promise<void> {
    try {
      const [ventanas, estado] = await Promise.all([this.agenda.cargar(), this.google.estado()]);
      const horario = deVentanas(ventanas);

      this.horario.set(horario);
      this.horarioGuardado.set(JSON.stringify(horario));
      this.estadoGoogle.set(estado);
    } catch (e: any) {
      this.errorAgenda.set(e?.error?.error ?? 'No se pudo cargar la agenda');
    }
  }

  async guardarHorario(): Promise<void> {
    const problema = validarHorario(this.horario());

    if (problema) {
      this.errorAgenda.set(problema);
      return;
    }

    this.guardandoHorario.set(true);
    this.errorAgenda.set('');

    try {
      const sincronizado = await this.agenda.guardar(aVentanas(this.horario()));

      this.horarioGuardado.set(JSON.stringify(this.horario()));
      this.wa.aviso.set(sincronizado ? 'Horario guardado y sincronizado con Google Calendar' : 'Horario guardado');
    } catch (e: any) {
      this.errorAgenda.set(e?.error?.error ?? 'No se pudo guardar el horario');
    } finally {
      this.guardandoHorario.set(false);
    }
  }

  async conectarGoogle(): Promise<void> {
    this.trabajandoGoogle.set(true);
    this.errorAgenda.set('');

    try {
      window.location.href = await this.google.url();
    } catch (e: any) {
      this.errorAgenda.set(e?.error?.error ?? 'No se pudo iniciar la conexión con Google');
      this.trabajandoGoogle.set(false);
    }
  }

  async desconectarGoogle(): Promise<void> {
    if (!confirm('¿Desconectar Google Calendar? El agente dejará de revisar y crear eventos en tu calendario.')) return;

    this.trabajandoGoogle.set(true);

    try {
      await this.google.desconectar();
      this.estadoGoogle.set({ conectado: false, email: null });
      this.wa.aviso.set('Google Calendar desconectado');
    } catch (e: any) {
      this.errorAgenda.set(e?.error?.error ?? 'No se pudo desconectar');
    } finally {
      this.trabajandoGoogle.set(false);
    }
  }
}
