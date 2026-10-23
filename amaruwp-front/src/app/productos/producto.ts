import { HttpClient, HttpErrorResponse, HttpEventType, HttpResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, filter, map, tap } from 'rxjs';
import { environment } from '../../environments/environment';

export interface ProductoFoto {
  id: number;
  ruta: string;
}

export interface ProductoFaq {
  pregunta: string;
  respuesta: string;
}

export interface Producto {
  id: number;
  nombre: string;
  descripcion: string | null;
  precio: number | null;
  requiere_cita: boolean;
  fotos: ProductoFoto[];
  faq: ProductoFaq[];
}

/** Body accepted by POST /productos and PUT /productos/{id}. */
export interface ProductoPayload {
  nombre: string;
  descripcion: string | null;
  precio: number | null;
  requiere_cita: boolean;
  faq: ProductoFaq[];
}

/** Partial body used when only a few fields change. */
export type ProductoPatch = Partial<ProductoPayload>;

/** Colores de los avisos, los mismos de amaruweb. */
export const COLOR_AVISO = {
  exito: '#0d9488',
  advertencia: '#b45309',
  error: '#b91c1c',
};

interface ListaResponse {
  status: boolean;
  productos: Producto[];
}

interface ProductoResponse {
  status: boolean;
  producto: Producto;
}

interface FotosResponse {
  status: boolean;
  guardadas: number;
  producto: Producto;
}

@Injectable({ providedIn: 'root' })
export class ProductoService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/productos`;

  list(): Observable<Producto[]> {
    return this.http
      .get<ListaResponse>(this.base)
      .pipe(map((response) => response.productos ?? []));
  }

  get(id: number): Observable<Producto> {
    return this.http
      .get<ProductoResponse>(`${this.base}/${id}`)
      .pipe(map((response) => response.producto));
  }

  create(payload: ProductoPayload): Observable<Producto> {
    return this.http
      .post<ProductoResponse>(this.base, payload)
      .pipe(map((response) => response.producto));
  }

  update(id: number, payload: ProductoPatch): Observable<Producto> {
    return this.http
      .put<ProductoResponse>(`${this.base}/${id}`, payload)
      .pipe(map((response) => response.producto));
  }

  remove(id: number): Observable<void> {
    return this.http.delete<void>(`${this.base}/${id}`);
  }

  /** Sube las fotos y avisa el porcentaje real enviado al servidor. */
  uploadFotos(id: number, files: File[], onProgreso?: (porcentaje: number) => void): Observable<FotosResponse> {
    const body = new FormData();

    for (const file of files) {
      // PHP solo arma un arreglo en $_FILES si el campo termina en []
      body.append('fotos[]', file, file.name);
    }

    return this.http
      .post<FotosResponse>(`${this.base}/${id}/fotos`, body, { reportProgress: true, observe: 'events' })
      .pipe(
        tap((event) => {
          if (event.type === HttpEventType.UploadProgress && event.total) {
            onProgreso?.(Math.round((event.loaded / event.total) * 100));
          }
        }),
        filter((event): event is HttpResponse<FotosResponse> => event.type === HttpEventType.Response),
        map((event) => event.body as FotosResponse),
      );
  }

  removeFoto(productoId: number, fotoId: number): Observable<void> {
    return this.http.delete<void>(`${this.base}/${productoId}/fotos/${fotoId}`);
  }

  fotoUrl(foto: ProductoFoto): string {
    return `${environment.apiUrl}/${foto.ruta}`;
  }
}

/** Backend errors arrive as { status: false, error: "..." }. */
export function mensajeDeError(error: unknown, fallback: string): string {
  if (error instanceof HttpErrorResponse) {
    const backendError = (error.error as { error?: unknown } | null)?.error;

    if (typeof backendError === 'string' && backendError.trim() !== '') {
      return backendError;
    }

    if (error.status === 0) {
      return 'No se pudo conectar con el servidor.';
    }
  }

  return fallback;
}
