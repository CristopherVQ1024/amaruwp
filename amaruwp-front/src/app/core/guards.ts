import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { Auth } from './auth';

export const sesionGuard: CanActivateFn = () => {
  const auth = inject(Auth);

  if (auth.haySesion()) return true;

  auth.limpiar();
  return inject(Router).createUrlTree(['/login']);
};

export const empresaGuard: CanActivateFn = () => {
  const auth = inject(Auth);

  return auth.tieneEmpresa() ? true : inject(Router).createUrlTree(['/registrar-empresa']);
};

export const invitadoGuard: CanActivateFn = () => {
  const auth = inject(Auth);
  const router = inject(Router);

  if (!auth.haySesion()) return true;

  return router.createUrlTree([auth.tieneEmpresa() ? '/inicio' : '/registrar-empresa']);
};
