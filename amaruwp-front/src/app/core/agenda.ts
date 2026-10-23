import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { environment } from '../../environments/environment';

export interface Ventana {
  dia_semana: number;
  hora_inicio: string;
  hora_fin: string;
  slot_minutos: number;
}

export const DIAS = [
  { valor: 1, nombre: 'Lunes' },
  { valor: 2, nombre: 'Martes' },
  { valor: 3, nombre: 'Miércoles' },
  { valor: 4, nombre: 'Jueves' },
  { valor: 5, nombre: 'Viernes' },
  { valor: 6, nombre: 'Sábado' },
  { valor: 7, nombre: 'Domingo' },
];

// duración fija de cada cita en minutos
export const SLOT_MINUTOS = 30;

// un horario global: los mismos desde y hasta para todos los días marcados
export interface Horario {
  dias: number[];
  desde: string;
  hasta: string;
}

export const horarioVacio = (): Horario => ({ dias: [], desde: '09:00', hasta: '18:00' });

export function aVentanas(h: Horario): Ventana[] {
  return [...h.dias].sort((a, b) => a - b).map((dia) => ({ dia_semana: dia, hora_inicio: h.desde, hora_fin: h.hasta, slot_minutos: SLOT_MINUTOS }));
}

// toma el primer horario guardado y marca los días que lo comparten
export function deVentanas(ventanas: Ventana[]): Horario {
  const primera = ventanas[0];

  if (!primera) return horarioVacio();

  const dias = ventanas.filter((v) => v.hora_inicio === primera.hora_inicio && v.hora_fin === primera.hora_fin).map((v) => v.dia_semana);

  return { dias, desde: primera.hora_inicio, hasta: primera.hora_fin };
}

// devuelve el problema del horario o una cadena vacía si está bien
export function validarHorario(h: Horario): string {
  if (h.dias.length === 0) return '';
  if (!h.desde || !h.hasta) return 'Completa la hora de inicio y fin';
  if (h.desde >= h.hasta) return 'La hora de inicio debe ser menor a la de fin';

  return '';
}

@Injectable({ providedIn: 'root' })
export class Agenda {
  private http = inject(HttpClient);
  private api = environment.apiUrl;

  async cargar(): Promise<Ventana[]> {
    const res = await firstValueFrom(this.http.get<{ disponibilidad: Ventana[] }>(`${this.api}/agenda/disponibilidad`));

    return res.disponibilidad;
  }

  async guardar(horarios: Ventana[]): Promise<boolean> {
    const res = await firstValueFrom(this.http.put<{ sincronizado_google: boolean }>(`${this.api}/agenda/disponibilidad`, { horarios }));

    return res.sincronizado_google;
  }
}

export interface EstadoGoogle {
  conectado: boolean;
  email: string | null;
}

@Injectable({ providedIn: 'root' })
export class GoogleCalendar {
  private http = inject(HttpClient);
  private api = environment.apiUrl;

  estado(): Promise<EstadoGoogle> {
    return firstValueFrom(this.http.get<EstadoGoogle>(`${this.api}/google/calendar/estado`));
  }

  async url(): Promise<string> {
    return (await firstValueFrom(this.http.get<{ url: string }>(`${this.api}/google/calendar/url`))).url;
  }

  async desconectar(): Promise<void> {
    await firstValueFrom(this.http.delete(`${this.api}/google/calendar`));
  }
}
