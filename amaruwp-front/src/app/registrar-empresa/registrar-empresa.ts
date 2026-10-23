import { ChangeDetectionStrategy, Component, DestroyRef, inject, signal } from '@angular/core';
import { FormArray, NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { Auth } from '../core/auth';
import { Icono } from '../core/icono';

interface Paso {
  titulo: string;
  desc: string;
}

@Component({
  selector: 'app-registrar-empresa',
  imports: [ReactiveFormsModule, Icono],
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
    { titulo: 'Teléfonos de contacto', desc: 'Los números que el agente puede compartir con tus clientes.' },
    { titulo: 'Productos y agenda', desc: 'Después cargas tu catálogo y defines tus horarios de atención.' },
  ];

  cargando = signal(false);
  enviado = signal(false);
  pasoActivo = signal(0);
  hover = signal(false);
  aurora = signal({ x: '50%', y: '50%' });
  foco = signal({ x: '-200px', y: '-200px' });
  cuadros = signal({ x: '0px', y: '0px', rot: '0deg' });
  desplazamiento = signal('');
  toast = signal({ visible: false, mensaje: '', color: '#b91c1c' });

  private pausado = false;
  private temporizadorToast?: ReturnType<typeof setTimeout>;

  form = this.fb.group({
    nombre: ['', [Validators.required, Validators.minLength(3)]],
    direccion: [''],
    descripcion: [''],
    telefonos: this.fb.array([this.nuevoTelefono()]),
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

  get telefonos(): FormArray {
    return this.form.controls.telefonos;
  }

  private nuevoTelefono() {
    return this.fb.control('', [Validators.required, Validators.pattern(/^[0-9]{9}$/)]);
  }

  agregarTelefono(): void {
    this.telefonos.push(this.nuevoTelefono());
  }

  quitarTelefono(i: number): void {
    if (this.telefonos.length > 1) this.telefonos.removeAt(i);
  }

  invalido(nombre: 'nombre'): boolean {
    const c = this.form.controls[nombre];
    return c.invalid && (c.touched || this.enviado());
  }

  telefonoInvalido(i: number): boolean {
    const c = this.telefonos.at(i);
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

    const v = this.form.getRawValue();
    this.cargando.set(true);

    try {
      await this.auth.registrarEmpresa({
        nombre: v.nombre.trim(),
        direccion: v.direccion.trim(),
        descripcion: v.descripcion.trim(),
        telefonos: v.telefonos.map((t: string) => '+51' + t),
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
