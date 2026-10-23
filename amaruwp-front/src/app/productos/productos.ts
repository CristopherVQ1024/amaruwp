import { ChangeDetectionStrategy, Component, DestroyRef, OnInit, inject, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { Icono } from '../core/icono';
import { COLOR_AVISO, Producto, ProductoService, mensajeDeError } from './producto';

@Component({
  selector: 'app-productos',
  imports: [Icono, RouterLink],
  templateUrl: './productos.html',
  styleUrl: './productos.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProductosPage implements OnInit {
  private readonly servicio = inject(ProductoService);
  private readonly router = inject(Router);

  readonly productos = signal<Producto[]>([]);
  readonly cargando = signal(true);
  readonly error = signal('');
  readonly confirmando = signal<Producto | null>(null);
  readonly eliminando = signal(false);
  toast = signal({ visible: false, mensaje: '', color: COLOR_AVISO.exito });

  private temporizadorToast?: ReturnType<typeof setTimeout>;

  private readonly formatoPrecio = new Intl.NumberFormat('es-PE', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });

  constructor() {
    // el formulario manda su aviso en el state de la navegacion al volver a la lista
    const state = this.router.currentNavigation()?.extras.state;

    if (typeof state?.['aviso'] === 'string') {
      this.avisar(state['aviso'], state['color'] ?? COLOR_AVISO.exito);
    }

    inject(DestroyRef).onDestroy(() => clearTimeout(this.temporizadorToast));
  }

  ngOnInit(): void {
    void this.cargar();
  }

  async cargar(): Promise<void> {
    this.cargando.set(true);
    this.error.set('');

    try {
      this.productos.set(await firstValueFrom(this.servicio.list()));
    } catch (error) {
      this.error.set(mensajeDeError(error, 'No se pudieron cargar los productos.'));
    } finally {
      this.cargando.set(false);
    }
  }

  editar(producto: Producto): void {
    void this.router.navigate(['/productos', producto.id]);
  }

  pedirEliminar(producto: Producto): void {
    this.confirmando.set(producto);
  }

  cerrarConfirmacion(): void {
    if (!this.eliminando()) {
      this.confirmando.set(null);
    }
  }

  async confirmarEliminar(): Promise<void> {
    const producto = this.confirmando();

    if (!producto) {
      return;
    }

    this.eliminando.set(true);

    try {
      await firstValueFrom(this.servicio.remove(producto.id));
      this.productos.update((actuales) => actuales.filter((item) => item.id !== producto.id));
      this.avisar('Producto eliminado.', COLOR_AVISO.exito);
      this.confirmando.set(null);
    } catch (error) {
      this.avisar(mensajeDeError(error, 'No se pudo eliminar el producto.'), COLOR_AVISO.error);
    } finally {
      this.eliminando.set(false);
    }
  }

  precio(producto: Producto): string {
    return producto.precio === null ? 'A consultar' : `S/ ${this.formatoPrecio.format(producto.precio)}`;
  }

  portada(producto: Producto): string | null {
    const foto = producto.fotos[0];

    return foto ? this.servicio.fotoUrl(foto) : null;
  }

  private avisar(mensaje: string, color: string): void {
    clearTimeout(this.temporizadorToast);
    this.toast.set({ visible: true, mensaje, color });
    this.temporizadorToast = setTimeout(() => this.toast.update((t) => ({ ...t, visible: false })), 3500);
  }
}
