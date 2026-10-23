import { Routes } from '@angular/router';
import { empresaGuard, invitadoGuard, sesionGuard } from './core/guards';

export const routes: Routes = [
  { path: '', pathMatch: 'full', redirectTo: 'login' },
  {
    path: 'login',
    canActivate: [invitadoGuard],
    loadComponent: () => import('./login/login').then((m) => m.Login),
  },
  {
    path: 'registrar-empresa',
    canActivate: [sesionGuard],
    loadComponent: () => import('./registrar-empresa/registrar-empresa').then((m) => m.RegistrarEmpresa),
  },
  {
    path: 'inicio',
    canActivate: [sesionGuard, empresaGuard],
    loadComponent: () => import('./inicio/inicio').then((m) => m.Inicio),
  },
  {
    path: 'productos',
    title: 'Productos y servicios',
    canActivate: [sesionGuard, empresaGuard],
    loadComponent: () => import('./productos/productos').then((m) => m.ProductosPage),
  },
  {
    path: 'productos/nuevo',
    title: 'Nuevo producto',
    canActivate: [sesionGuard, empresaGuard],
    loadComponent: () => import('./productos/producto-form/producto-form').then((m) => m.ProductoForm),
  },
  {
    path: 'productos/:id',
    title: 'Editar producto',
    canActivate: [sesionGuard, empresaGuard],
    loadComponent: () => import('./productos/producto-form/producto-form').then((m) => m.ProductoForm),
  },
  { path: '**', redirectTo: 'login' },
];
