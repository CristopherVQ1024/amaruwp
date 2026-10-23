import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { environment } from '../../environments/environment';
import { Auth } from './auth';

const PUBLICAS = ['/auth/google', '/auth/login', '/auth/registrar'];

export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const auth = inject(Auth);
  const router = inject(Router);
  const esApi = req.url.startsWith(environment.apiUrl);
  const esPublica = PUBLICAS.some((ruta) => req.url.endsWith(ruta));

  if (esApi && auth.token && !esPublica) {
    req = req.clone({ setHeaders: { Authorization: `Bearer ${auth.token}` } });
  }

  return next(req).pipe(
    catchError((err: HttpErrorResponse) => {
      if (err.status === 401 && esApi && !esPublica) {
        auth.limpiar();
        router.navigate(['/login']);
      }

      return throwError(() => err);
    }),
  );
};
