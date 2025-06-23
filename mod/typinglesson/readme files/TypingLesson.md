# Typing Lesson Activity for Moodle

## Overview

`typinglesson` is a custom Moodle activity module designed to provide structured and customizable typing lessons within Moodle courses. This module allows teachers to create and manage typing lessons, assign them to students, and track their progress.

The goal is to create a practical typing learning tool that supports different lesson types and user progress tracking, fully integrated with Moodle's activity and permission system.

---

## Features

- ✅ Custom activity module named `typinglesson`  
- ✅ Supports adding the activity to a course via Moodle's UI  
- ✅ Database structure defined via `install.xml`, including:  
  - `typinglesson` – main module instance table  
  - `typing_lessons` – internal lessons within a module  
  - `typing_lesson_types` – defines types of lessons  
  - `typing_custom_lessons` – user-defined lessons (optional/future use)  
  - `custom_lesson_progress` – tracks individual user progress  
- ✅ Basic view page (`view.php`) with dynamic lesson list display  
- ✅ Lesson listing per course (`index.php`)  
- ✅ Admin form (`mod_form.php`) to create/edit the typing lesson module instance  
- ✅ Basic CSS included (`icon.css`)  
- ✅ Language strings (`lang/en/typinglesson.php`)  
- ✅ Unit test setup started (`tests/mod_typinglesson_basic_test.php`)  

---

## Progress Status

### Completed:  
- ✅ Activity structure initialized using Moodle's mod template  
- ✅ Tables created and installed successfully via `install.xml`  
- ✅ Lesson creation page in development (admin only)  
- ✅ `lib.php` configured to support standard Moodle features  
- ✅ Styling support added  
- ✅ Initial unit testing framework in place  

---

## Installation

1. Clone or copy this module into your Moodle installation at:  
   `mod/typinglesson`  
2. Visit the Moodle site as admin to trigger the plugin installation.  
3. The necessary tables will be created automatically.  
4. Add a new activity of type **Typing Lesson** to a course to get started.  

---

## Folder Structure

- `mod_form.php` – admin form for adding/editing the module instance  
- `view.php` – main page to display typing lessons and allow student interaction  
- `index.php` – lists all typing lessons in a course  
- `lib.php` – library functions supporting Moodle module API  
- `db/install.xml` – XMLDB schema defining database tables  
- `lang/en/typinglesson.php` – language strings  
- `css/icon.css` – basic styling  
- `tests/` – PHPUnit tests  
- Other supporting files and folders as the module grows  

