# Proyecto WordPress: Glasswing Voluntariado

Este repositorio contiene el código fuente personalizado para la plataforma de gestión de voluntariado de Glasswing, desarrollada en WordPress.

## Estructura principal

- **wp-content/plugins/gw-manager/** → Plugin principal para gestión de países, proyectos, emparejamientos y academia.
- **wp-content/themes/** → Temas utilizados en el proyecto.
- **wp-content/uploads/** → *Ignorado en el repositorio por contener archivos pesados.*

## ¿Qué incluye este repositorio?
- Todo el código personalizado (plugins, temas).
- Archivos y configuración necesarios para restaurar el entorno WordPress.
- Archivo `.gitignore` para evitar archivos temporales/pesados.
- Este `README.md` para orientar al cliente y al equipo.

## ¿Qué NO incluye?
- Base de datos MySQL (debe compartirse por fuera, como un archivo `.sql`).
- Archivos de medios/pdfs pesados (`wp-content/uploads/`).

## Instalación rápida (local)

1. Clona el repositorio en tu entorno local.
2. Instala WordPress si aún no lo tienes.
3. Importa la base de datos proporcionada por el desarrollador.
4. Copia y configura `wp-config.php` con tus credenciales locales.
5. ¡Listo para probar!

## Notas
- Si tienes dudas o encuentras errores, contacta al desarrollador.
- Este proyecto fue desarrollado por [Carlos Montalvo].
