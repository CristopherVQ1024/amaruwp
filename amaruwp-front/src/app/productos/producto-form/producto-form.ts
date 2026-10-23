import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  OnInit,
  computed,
  inject,
  signal,
} from '@angular/core';
import { FormBuilder, FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { Icono } from '../../core/icono';
import { COLOR_AVISO, ProductoFoto, ProductoPayload, ProductoService, mensajeDeError } from '../producto';

type FaqGroup = FormGroup<{ pregunta: FormControl<string>; respuesta: FormControl<string> }>;

interface NuevaFoto {
  file: File;
  url: string;
}

const MAX_BYTES = 5 * 1024 * 1024;

/** Mismos tipos que acepta ProductoService::MIMES en el backend. */
export const TIPOS_FOTO = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

@Component({
  selector: 'app-producto-form',
  imports: [ReactiveFormsModule, Icono],
  templateUrl: './producto-form.html',
  styleUrl: './producto-form.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProductoForm implements OnInit {
  private readonly fb = inject(FormBuilder);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly servicio = inject(ProductoService);
  private readonly destroyRef = inject(DestroyRef);

  readonly tiposFoto = TIPOS_FOTO.join(',');

  /** Id del producto que se edita, o null si es nuevo. */
  readonly productoId = signal<number | null>(null);
  readonly cargando = signal(false);
  readonly errorCarga = signal('');

  readonly guardando = signal(false);
  readonly subiendo = signal(false);
  readonly progreso = signal(0);
  readonly error = signal('');
  readonly fotos = signal<ProductoFoto[]>([]);
  readonly fotosAEliminar = signal<ProductoFoto[]>([]);
  readonly nuevasFotos = signal<NuevaFoto[]>([]);
  toast = signal({ visible: false, mensaje: '', color: COLOR_AVISO.advertencia });

  private temporizadorToast?: ReturnType<typeof setTimeout>;

  readonly esNuevo = computed(() => this.productoId() === null);
  readonly portada = computed(() => {
    const existente = this.fotos()[0];

    return existente ? this.servicio.fotoUrl(existente) : (this.nuevasFotos()[0]?.url ?? null);
  });
  readonly totalFotos = computed(() => this.fotos().length + this.nuevasFotos().length);

  /** Id del producto creado en esta pantalla, para que un reintento actualice en vez de duplicar. */
  private creadoId: number | null = null;

  readonly faq = this.fb.array<FaqGroup>([]);

  readonly form = this.fb.group({
    nombre: this.fb.nonNullable.control('', [Validators.required, Validators.maxLength(150)]),
    descripcion: this.fb.nonNullable.control(''),
    precio: this.fb.control<number | null>(null, [Validators.min(0)]),
    requiere_cita: this.fb.nonNullable.control(false),
    faq: this.faq,
  });

  ngOnInit(): void {
    this.destroyRef.onDestroy(() => {
      this.liberarPreviews();
      clearTimeout(this.temporizadorToast);
    });

    const param = this.route.snapshot.paramMap.get('id');

    if (param !== null) {
      void this.cargar(param);
    }
  }

  private async cargar(param: string): Promise<void> {
    const id = Number(param);

    if (!Number.isInteger(id) || id <= 0) {
      this.errorCarga.set('Producto no encontrado.');
      return;
    }

    this.productoId.set(id);
    this.cargando.set(true);

    try {
      const producto = await firstValueFrom(this.servicio.get(id));

      this.form.patchValue({
        nombre: producto.nombre,
        descripcion: producto.descripcion ?? '',
        precio: producto.precio,
        requiere_cita: producto.requiere_cita,
      });
      this.fotos.set(producto.fotos);

      for (const item of producto.faq) {
        this.agregarFaq(item.pregunta, item.respuesta);
      }
    } catch (error) {
      this.errorCarga.set(mensajeDeError(error, 'No se pudo cargar el producto.'));
    } finally {
      this.cargando.set(false);
    }
  }

  fotoUrl(foto: ProductoFoto): string {
    return this.servicio.fotoUrl(foto);
  }

  agregarFaq(pregunta = '', respuesta = ''): void {
    this.faq.push(
      this.fb.group({
        pregunta: this.fb.nonNullable.control(pregunta, [Validators.maxLength(500)]),
        respuesta: this.fb.nonNullable.control(respuesta),
      }),
    );
  }

  quitarFaq(index: number): void {
    this.faq.removeAt(index);
  }

  onFotosSeleccionadas(event: Event): void {
    const input = event.target as HTMLInputElement;
    const aceptadas: NuevaFoto[] = [];

    for (const file of Array.from(input.files ?? [])) {
      if (!TIPOS_FOTO.includes(file.type)) {
        this.avisar(`"${file.name}" no es JPG, PNG, WEBP ni GIF.`);
        continue;
      }

      if (file.size > MAX_BYTES) {
        this.avisar(`"${file.name}" supera los 5 MB.`);
        continue;
      }

      aceptadas.push({ file, url: URL.createObjectURL(file) });
    }

    if (aceptadas.length > 0) {
      this.nuevasFotos.update((actuales) => [...actuales, ...aceptadas]);
    }

    input.value = '';
  }

  quitarNuevaFoto(index: number): void {
    const foto = this.nuevasFotos()[index];

    if (foto) {
      URL.revokeObjectURL(foto.url);
    }

    this.nuevasFotos.update((actuales) => actuales.filter((_, i) => i !== index));
  }

  marcarFotoParaEliminar(foto: ProductoFoto): void {
    this.fotos.update((actuales) => actuales.filter((item) => item.id !== foto.id));
    this.fotosAEliminar.update((actuales) => [...actuales, foto]);
  }

  deshacerEliminaciones(): void {
    this.fotos.update((actuales) => [...actuales, ...this.fotosAEliminar()]);
    this.fotosAEliminar.set([]);
  }

  /** La X sobre la imagen grande quita la primera foto, sea ya guardada o recién elegida. */
  quitarPortada(): void {
    const existente = this.fotos()[0];

    if (existente) {
      this.marcarFotoParaEliminar(existente);
    } else {
      this.quitarNuevaFoto(0);
    }
  }

  volver(): void {
    void this.router.navigate(['/productos']);
  }

  async guardar(): Promise<void> {
    if (this.guardando()) {
      return;
    }

    this.error.set('');

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.error.set('Revisa los campos marcados.');
      return;
    }

    this.guardando.set(true);

    let id = this.productoId() ?? this.creadoId;

    try {
      const payload = this.construirPayload();

      if (id === null) {
        id = (await firstValueFrom(this.servicio.create(payload))).id;
        this.creadoId = id;
      } else {
        await firstValueFrom(this.servicio.update(id, payload));
      }
    } catch (error) {
      this.error.set(mensajeDeError(error, 'No se pudo guardar el producto.'));
      this.guardando.set(false);
      return;
    }

    // se muestra en la lista despues de navegar, si no hubo advertencias es el de exito
    let advertencia: string | null = null;

    try {
      const pendientes = this.nuevasFotos();

      if (pendientes.length > 0) {
        this.progreso.set(0);
        this.subiendo.set(true);

        const subida = await firstValueFrom(
          this.servicio.uploadFotos(
            id,
            pendientes.map((item) => item.file),
            (porcentaje) => this.progreso.set(porcentaje),
          ),
        );

        // ya estan en el servidor, un reintento no debe volver a subirlas
        this.liberarPreviews();
        this.nuevasFotos.set([]);

        if (subida.guardadas < pendientes.length) {
          advertencia = `Producto guardado, pero solo se subieron ${subida.guardadas} de ${pendientes.length} fotos.`;
        }
      }

      for (const foto of this.fotosAEliminar()) {
        try {
          await firstValueFrom(this.servicio.removeFoto(id, foto.id));
        } catch {
          advertencia = 'Producto guardado, pero una foto no se pudo eliminar.';
        }
      }

      await this.router.navigate(['/productos'], {
        state: advertencia
          ? { aviso: advertencia, color: COLOR_AVISO.advertencia }
          : { aviso: this.esNuevo() ? 'Producto creado.' : 'Producto actualizado.', color: COLOR_AVISO.exito },
      });
    } catch (error) {
      this.error.set(
        mensajeDeError(
          error,
          'El producto se guardó, pero las fotos no se pudieron subir. Revisa que sean JPG, PNG, WEBP o GIF de hasta 5 MB y vuelve a guardar.',
        ),
      );
    } finally {
      this.subiendo.set(false);
      this.guardando.set(false);
    }
  }

  private construirPayload(): ProductoPayload {
    const raw = this.form.getRawValue();

    return {
      nombre: raw.nombre.trim(),
      descripcion: raw.descripcion.trim() === '' ? null : raw.descripcion.trim(),
      precio: raw.precio === null ? null : Number(raw.precio),
      requiere_cita: raw.requiere_cita,
      faq: raw.faq
        .map((item) => ({
          pregunta: item.pregunta.trim(),
          respuesta: item.respuesta.trim(),
        }))
        .filter((item) => item.pregunta !== '' && item.respuesta !== ''),
    };
  }

  private liberarPreviews(): void {
    for (const foto of this.nuevasFotos()) {
      URL.revokeObjectURL(foto.url);
    }
  }

  private avisar(mensaje: string): void {
    clearTimeout(this.temporizadorToast);
    this.toast.set({ visible: true, mensaje, color: COLOR_AVISO.advertencia });
    this.temporizadorToast = setTimeout(() => this.toast.update((t) => ({ ...t, visible: false })), 3500);
  }
}
