# Proyecto WordPress: Glasswing Voluntariado

Este repositorio contiene el código fuente personalizado para la plataforma de gestión de voluntariado Glasswing, desarrollada en WordPress.

---

## 🚀 Cómo clonar y correr el proyecto localmente

### 1. **Requisitos previos**

- Tener [XAMPP](https://www.apachefriends.org/es/index.html) instalado en tu computadora (o MAMP/LAMP/WAMP equivalente).
- Tener [Git](https://git-scm.com/) instalado.
- Acceso a un archivo de base de datos MySQL (`.sql`) y al archivo de credenciales (`credentials.json`), los cuales se enviarán por separado.

---

### 2. **Clonar el repositorio**

Abre la terminal y ejecuta:

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/
git clone https://github.com/USUARIO/gwproject.git

3. Importar la base de datos
	•	Solicita el archivo .sql al desarrollador.
	•	Entra a phpMyAdmin, crea una base de datos nueva (por ejemplo, gwproject).
	•	Importa el archivo .sql proporcionado.

4. Configurar wp-config.php
	•	Copia el archivo wp-config-sample.php y renómbralo como wp-config.php.
	•	Edita los siguientes datos con la información de tu entorno local:

define('DB_NAME', 'gwproject'); // Nombre de tu base de datos
define('DB_USER', 'root'); // Usuario local
define('DB_PASSWORD', ''); // Contraseña local (usualmente vacía en XAMPP)
define('DB_HOST', 'localhost');

	•	Guarda los cambios.

5. Agregar archivos de credenciales
	•	Solicita el archivo credentials.json al desarrollador.
	•	Coloca el archivo en:

wp-content/plugins/gw-manager/credentials.json

6. Iniciar XAMPP y acceder al sitio
	•	Enciende Apache y MySQL desde el panel de control de XAMPP.
	•	Accede al proyecto desde tu navegador en:

http://localhost/gwproject/

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

Este proyecto fue desarrollado por [Carlos Montalvo].
Contacto: [carlos.mont.92@hotmail.com]
