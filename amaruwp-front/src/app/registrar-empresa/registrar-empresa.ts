import { ChangeDetectionStrategy, Component, DestroyRef, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { HorarioEditor } from '../agenda/horario-editor';
import { Horario, aVentanas, horarioVacio, validarHorario } from '../core/agenda';
import { Auth } from '../core/auth';
import { Icono } from '../core/icono';

interface Paso {
  titulo: string;
  desc: string;
}

@Component({
  selector: 'app-registrar-empresa',
  imports: [ReactiveFormsModule, Icono, HorarioEditor],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './registrar-empresa.html',
  styleUrl: './registrar-empresa.scss',
})
export class RegistrarEmpresa {
  private fb = inject(NonNullableFormBuilder);
  private auth = inject(Auth);
  private router = inject(Router);

  readonly pasos: Paso[] = [
    { titulo: 'Datos de tu negocio', desc: 'Nombre, dirección y descripción para que el agente hable de tu empresa.' },
    { titulo: 'Teléfono y horario', desc: 'El número que el agente comparte y los horarios en que atiendes citas.' },
    { titulo: 'Catálogo y WhatsApp', desc: 'Después cargas tus productos y conectas tu WhatsApp Business.' },
  ];

  cargando = signal(false);
  enviado = signal(false);
  pasoActivo = signal(0);
  hover = signal(false);
  aurora = signal({ x: '50%', y: '50%' });
  foco = signal({ x: '-200px', y: '-200px' });
  cuadros = signal({ x: '0px', y: '0px', rot: '0deg' });
  desplazamiento = signal('');
  horario = signal<Horario>(horarioVacio());
  toast = signal({ visible: false, mensaje: '', color: '#b91c1c' });

  private pausado = false;
  private temporizadorToast?: ReturnType<typeof setTimeout>;

  form = this.fb.group({
    nombre: ['', [Validators.required, Validators.minLength(3)]],
    direccion: [''],
    descripcion: [''],
    telefono: ['', [Validators.required, Validators.pattern(/^[0-9]{9}$/)]],
  });

  constructor() {
    const rotacion = setInterval(() => {
      if (!this.pausado) this.pasoActivo.update((p) => (p + 1) % this.pasos.length);
    }, 4500);

    inject(DestroyRef).onDestroy(() => {
      clearInterval(rotacion);
      clearTimeout(this.temporizadorToast);
    });
  }

  invalido(nombre: 'nombre' | 'telefono'): boolean {
    const c = this.form.controls[nombre];
    return c.invalid && (c.touched || this.enviado());
  }

  elegirPaso(i: number): void {
    this.pasoActivo.set(i);
    this.pausado = true;
    setTimeout(() => (this.pausado = false), 6000);
  }

  moverVisual(e: MouseEvent): void {
    const panel = e.currentTarget as HTMLElement;
    const r = panel.getBoundingClientRect();
    const px = e.clientX - r.left;
    const py = e.clientY - r.top;
    const nx = px / r.width - 0.5;
    const ny = py / r.height - 0.5;

    this.hover.set(true);
    this.foco.set({ x: px + 'px', y: py + 'px' });
    this.aurora.set({ x: (px / r.width) * 100 + '%', y: (py / r.height) * 100 + '%' });
    this.desplazamiento.set(`translate3d(${nx * 10}px, ${ny * 10}px, 0)`);
    this.cuadros.set({ x: nx * -28 + 'px', y: ny * -28 + 'px', rot: nx * 6 + 'deg' });
  }

  salirVisual(): void {
    this.hover.set(false);
    this.desplazamiento.set('');
    this.cuadros.set({ x: '0px', y: '0px', rot: '0deg' });
    this.aurora.set({ x: '50%', y: '50%' });
  }

  async enviar(): Promise<void> {
    this.enviado.set(true);

    if (this.form.invalid || this.cargando()) {
      this.form.markAllAsTouched();
      return;
    }

    const problema = validarHorario(this.horario());

    if (problema) {
      this.avisar(problema);
      return;
    }

    const v = this.form.getRawValue();
    this.cargando.set(true);

    try {
      await this.auth.registrarEmpresa({
        nombre: v.nombre.trim(),
        direccion: v.direccion.trim(),
        descripcion: v.descripcion.trim(),
        telefonos: ['+51' + v.telefono],
        horarios: aVentanas(this.horario()),
      });
      await this.router.navigate(['/inicio']);
    } catch (e: any) {
      this.avisar(e?.error?.error ?? 'No se pudo registrar la empresa');
    } finally {
      this.cargando.set(false);
    }
  }

  cancelar(): void {
    this.auth.salir();
  }

  private avisar(mensaje: string): void {
    clearTimeout(this.temporizadorToast);
    this.toast.set({ visible: true, mensaje, color: '#b91c1c' });
    this.temporizadorToast = setTimeout(() => this.toast.update((t) => ({ ...t, visible: false })), 3500);
  }
}
