# AI Academic - University Management System

A modern, AI-powered academic management system built with **Laravel 12**. The platform leverages the Google Gemini API to automate the extraction of academic data (courses and student transcripts) from raw PDF and Excel files, providing a seamless course registration and tracking experience for both students and administrators.

## 🚀 Key Features

### 1. AI-Powered Data Extraction (Admin Setup)
- **PDF Course Parsing**: Upload university bylaws (لائحة) in PDF format. The system reads the PDF (using `smalot/pdfparser`) and sends the text to Gemini AI to extract structured course data: *Course Code, Name, Credit Hours, and Prerequisites*.
- **Excel Transcript Parsing**: Upload student grades via Excel sheets. The system processes each sheet individually (using `PhpSpreadsheet` in data-only mode to conserve memory), extracting the student's ID and all courses taken along with their pass/fail status.
- **Smart Arabic Text Handling**: Includes a custom algorithm to detect and automatically reverse "visual-order" Arabic text commonly found in legacy PDFs, ensuring course names are stored correctly in the database.
- **Resilient AI Pipeline**: The `GeminiService` includes partial JSON recovery for truncated AI responses, automatic retries for rate limits (HTTP 429), and sleep/retry mechanisms for server overloads (HTTP 503). All processing is handled asynchronously via Laravel Queues.

### 2. Student Course Registration System
- **Regular Course Requests**: Students can apply to register for courses for the upcoming semester.
- **Robust Validations**: The system automatically prevents registration if:
  - The student already has a pending or approved request for the course.
  - The student has already passed the course.
  - The student has not passed the required prerequisite for the course.
- **Graduation Requests**: Special handling for graduation material requests, allowing students to add custom notes for the academic adviser.

### 3. Admin Dashboard & Tracking
- **Request Management**: Administrators can view, approve, or reject material registration requests, while leaving mandatory notes for rejected requests.
- **Academic Progress**: Track individual student progress, viewing their completed courses, currently enrolled courses, and remaining credits.
- **System Overview**: High-level metrics showing the status of AI parsing jobs, total registered students, and pending requests.

## 🛠️ Tech Stack

- **Backend Framework**: Laravel 12 (PHP 8.2+)
- **Database**: MySQL (utf8mb4 encoding for full Arabic support)
- **Queue System**: Laravel Queues (Database/Redis) for background AI processing
- **AI Integration**: Google Gemini API (`gemini-2.0-flash` / `gemini-1.5-flash`)
- **Document Parsers**: 
  - `smalot/pdfparser` (PDF extraction)
  - `phpoffice/phpspreadsheet` (Excel reading)

## ⚙️ Installation & Setup

1. **Clone the repository:**
   ```bash
   git clone https://github.com/sherif-ashraf512/ai_acadimic.git
   cd ai_acadimic
   ```

2. **Install PHP dependencies:**
   ```bash
   composer install
   ```

3. **Environment Setup:**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
   Update the `.env` file with your database credentials and add your Gemini API Key:
   ```env
   GEMINI_API_KEY=your_api_key_here
   ```

4. **Run Migrations & Storage Link:**
   ```bash
   php artisan migrate
   php artisan storage:link
   ```

5. **Start the Application:**
   ```bash
   # Start the local development server
   php artisan serve

   # In a separate terminal window, start the queue worker for AI processing
   php artisan queue:work
   ```

## 🧠 AI Processing Workflow (How it Works)

1. The Admin uploads the PDF & Excel files via the Setup Dashboard.
2. A `ProcessSetupFilesJob` is dispatched to the background queue.
3. The job extracts text locally to bypass Gemini's binary file token limits.
4. `GeminiService` formats a strict prompt and sends the text to the API.
5. The JSON response is parsed (with fallback recovery if truncated), normalized, and saved directly to the database.
6. The Admin can track the progress of the job in real-time, catching any specific errors per student sheet.

## 📄 License
This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
