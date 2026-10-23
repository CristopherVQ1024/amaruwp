import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { GoogleAuthProvider, signInWithPopup, signOut } from 'firebase/auth';
import { firstValueFrom } from 'rxjs';
import { environment } from '../../environments/environment';
import { firebaseAuth, firebaseConfigurado } from './firebase';

export interface Usuario {
  id: number;
  email: string;
  nombre: string | null;
  foto: string | null;
  id_empresa: number | null;
}

export interface NuevaEmpresa {
  nombre: string;
  direccion: string;
  descripcion: string;
  telefonos: string[];
  horarios: { dia_semana: number; hora_inicio: string; hora_fin: string; slot_minutos: number }[];
}

interface Sesion {
  status: boolean;
  token: string;
  user: Usuario;
}

const CLAVE_TOKEN = 'amaruwp_token';
const CLAVE_USUARIO = 'amaruwp_usuario';

@Injectable({ providedIn: 'root' })
export class Auth {
  private http = inject(HttpClient);
  private router = inject(Router);

  private tokenSig = signal<string | null>(localStorage.getItem(CLAVE_TOKEN));
  private usuarioSig = signal<Usuario | null>(this.leerUsuario());

  readonly usuario = this.usuarioSig.asReadonly();
  readonly tieneEmpresa = computed(() => !!this.usuarioSig()?.id_empresa);

  get token(): string | null {
    return this.tokenSig();
  }

  haySesion(): boolean {
    const token = this.tokenSig();

    if (!token) return false;

    try {
      const { exp } = JSON.parse(atob(token.split('.')[1]));
      return !!exp && exp * 1000 > Date.now();
    } catch {
      return false;
    }
  }

  async entrarConGoogle(): Promise<Usuario> {
    if (!firebaseAuth || !firebaseConfigurado) {
      throw new Error('Firebase no esta configurado en environment.ts');
    }

    const resultado = await signInWithPopup(firebaseAuth, new GoogleAuthProvider());
    const credential = await resultado.user.getIdToken();
    const sesion = await firstValueFrom(
      this.http.post<Sesion>(`${environment.apiUrl}/auth/google`, { credential }),
    );

    return this.guardar(sesion);
  }

  async registrarEmpresa(datos: NuevaEmpresa): Promise<Usuario> {
    const sesion = await firstValueFrom(
      this.http.post<Sesion>(`${environment.apiUrl}/auth/registrar-empresa`, datos),
    );

    return this.guardar(sesion);
  }

  async salir(): Promise<void> {
    if (this.tokenSig()) {
      await firstValueFrom(this.http.post(`${environment.apiUrl}/auth/logout`, {})).catch(() => null);
    }

    if (firebaseAuth) await signOut(firebaseAuth).catch(() => null);

    this.limpiar();
    await this.router.navigate(['/login']);
  }

  limpiar(): void {
    localStorage.removeItem(CLAVE_TOKEN);
    localStorage.removeItem(CLAVE_USUARIO);
    this.tokenSig.set(null);
    this.usuarioSig.set(null);
  }

  private guardar(sesion: Sesion): Usuario {
    localStorage.setItem(CLAVE_TOKEN, sesion.token);
    localStorage.setItem(CLAVE_USUARIO, JSON.stringify(sesion.user));
    this.tokenSig.set(sesion.token);
    this.usuarioSig.set(sesion.user);

    return sesion.user;
  }

  private leerUsuario(): Usuario | null {
    try {
      return JSON.parse(localStorage.getItem(CLAVE_USUARIO) ?? 'null');
    } catch {
      return null;
    }
  }
}
