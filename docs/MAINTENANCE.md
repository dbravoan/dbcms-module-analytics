# Mantener Analytics

## Garantías del paquete

Conservar `CampaignAttributionGatewayInterface` propio, credenciales cifradas y aisladas, estados available/unavailable/stale e intervalo inclusivo máximo de 366 días. Una respuesta fallida no equivale a ceros. La conectividad real requiere propiedad y credenciales de pruebas.

## Composiciones de prueba

El perfil mínimo comprueba disponibilidad y emisión de tracking. `integration` ejecuta reporting GA4, credenciales, caché y consentimiento HTTP, incluidos los temas públicos declarados. Google se simula en la aceptación automática.

Las dependencias exactas y los casos pertenecen a `tests/package.php`;
`composer profiles` enumera las composiciones. Usa `DBCMS_TEST_PROFILE` para
seleccionarlas. Los lockfiles públicos de `tests/fixture` se regeneran con
`composer fixture:lock` al modificar el cierre Composer.

Para comandos Docker, calidad y smoke del distribuible, utiliza la
[guía común de pruebas](https://github.com/dbravoan/dbcms-module-template/blob/main/docs/TESTING.md).
La publicación de este repositorio sigue la
[guía común de publicación](https://github.com/dbravoan/dbcms-module-template/blob/main/docs/PUBLICATION.md).
La versión del módulo es independiente; su línea actual requiere core `^0.1`.

Las reglas de arquitectura y extensión tienen su única fuente en
[core](https://github.com/dbravoan/dbcms-core/tree/main/docs).

La aceptación en navegador vive en `tests/Browser`: fixture mínimo de este módulo,
recorrido admin y móvil en ambas marcas. Analytics intercepta además GA4. Usa una
aplicación marcada y una base `dbcms_browser_*`; el procedimiento común de
[pruebas](https://github.com/dbravoan/dbcms-module-template/blob/main/docs/TESTING.md)
describe las variables y la ejecución.
