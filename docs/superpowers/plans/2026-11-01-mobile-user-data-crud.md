# Mobile Application User Profile CRUD Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement full, secure CRUD operations (Create session, Read profile, Update profile/password, Delete/logout session) in the Flutter mobile application with server-enforced security guards preventing alteration of registered email and mobile numbers.

**Architecture:** Client-server REST architecture where Flutter mobile app (`callnowMobileApp`) communicates with CallNow CRM backend API (`/api/v1/auth/*`) using Bearer token authentication, cached offline with `SharedPreferences` in `ConfigService`, and consumed by UI components in `ProfileScreen` and `LoginScreen`.

**Tech Stack:** 
- Mobile App: Flutter 3.x / Dart 3.x, `http: ^1.2.0`, `shared_preferences: ^2.2.0`
- Backend API: PHP 8.x, MySQL (`mysqli`), RESTful JSON API with Bearer token authentication

**Spec:** Integration contract between CallNow CRM Backend (`c:\xampp\htdocs\callnowCRM\api\v1\auth\index.php`) and Flutter Mobile App (`e:\Projects\FlutterProjects\callnowMobileApp`).

---

## Global Constraints

1. **Security Guard for Email & Mobile**: Email address and phone/mobile number are immutable from the mobile application. The server strictly rejects modifications with HTTP 400. In Flutter UI, email and phone fields must be disabled (`enabled: false`) with padlock icons and guidance text ("Contact CRM Administrator").
2. **URL Normalization**: All base URLs must be trimmed of trailing slashes before appending paths to prevent double-slash (`//auth/me`) routing errors.
3. **Sound Null Safety**: All Dart models and service methods must handle missing/null keys gracefully using null-coalescing defaults.
4. **Offline Resilience**: Profile screen must immediately display cached `ConfigService.userData` while fetching fresh server data in the background.

---

## Review Focus

1. **Expired or Invalid Bearer Token**: If any API call returns HTTP 401, clear local cache (`ConfigService.clearAuthData()`) and redirect user to `LoginScreen`.
2. **Attempted Alteration of Email or Phone**: If an update payload contains modified email/phone, backend returns HTTP 400; UI must display the server's error message in a snackbar and retain previous values.
3. **Network Failure during Profile Fetch**: Fall back gracefully to `ConfigService.userData` cache so user experience is uninterrupted.
4. **Double Slash Trailing Slash Bug**: `ConfigService.baseUrl` and `ApiService._baseUrl` must strip trailing slashes to guarantee clean URIs.
5. **Telecaller Null Email/Phone Fallback**: If backend user has null email/mobile, UI must display `"Not provided on server"` instead of crashing or showing `"[]"`.

---

## Backend Endpoint Readiness Verification

All 10 required user CRUD endpoints have been executed and verified on the server:

| # | Operation | HTTP Method & URI | Expected Response | Test Status |
|---|---|---|---|---|
| 1 | **Create Session** | `POST /api/v1/auth/login` | 200 OK + Bearer token + full user details | **PASSED** |
| 2 | **Read Profile** | `GET /api/v1/auth/me` | 200 OK + full joined user & team profile | **PASSED** |
| 3 | **Read Profile (Alias)**| `GET /api/v1/auth/profile` | 200 OK + full user profile object | **PASSED** |
| 4 | **Update Allowed Fields** | `POST /api/v1/auth/profile` | 200 OK + updated name and company | **PASSED** |
| 5 | **Security Guard (Email)**| `POST /api/v1/auth/profile` (email changed) | 400 Bad Request (Action strictly blocked) | **PASSED** |
| 6 | **Security Guard (Mobile)**| `POST /api/v1/auth/profile` (phone changed) | 400 Bad Request (Action strictly blocked) | **PASSED** |
| 7 | **Update Password** | `POST /api/v1/auth/change-password` | 200 OK + Password updated successfully | **PASSED** |
| 8 | **Verify Credentials** | `POST /api/v1/auth/login` (new password) | 200 OK + authenticated with new password | **PASSED** |
| 9 | **Delete Session (Logout)**| `POST /api/v1/auth/logout` | 200 OK + session token revoked | **PASSED** |
| 10| **Token Revocation Check**| `GET /api/v1/auth/me` (revoked token) | 401 Unauthorized (Access denied) | **PASSED** |

---

## Task Decomposition

### Task 1: Create User Profile Data Model (`UserProfile`)

**Files:**
- Create: `e:\Projects\FlutterProjects\callnowMobileApp\lib\model\user_profile.dart`

**Interfaces:**
- Produces: `class UserProfile` with properties (`id`, `name`, `username`, `email`, `mobile`, `role`, `companyName`, `teamName`, `department`, `joinDate`, `profilePhotoUrl`), `fromJson(Map<String, dynamic> json)` factory, and `toJson()` method.

- [ ] **Step 1: Define `UserProfile` class and factory constructors**
  Create `lib/model/user_profile.dart` with sound null-safety:
  ```dart
  class UserProfile {
    final int id;
    final String name;
    final String username;
    final String email;
    final String mobile;
    final String role;
    final String companyName;
    final String teamName;
    final String department;
    final String joinDate;
    final String? profilePhotoUrl;

    UserProfile({
      required this.id,
      required this.name,
      required this.username,
      required this.email,
      required this.mobile,
      required this.role,
      required this.companyName,
      required this.teamName,
      required this.department,
      required this.joinDate,
      this.profilePhotoUrl,
    });

    factory UserProfile.fromJson(Map<String, dynamic> json) {
      final user = (json['user'] is Map) ? Map<String, dynamic>.from(json['user']) : json;
      return UserProfile(
        id: int.tryParse(user['id']?.toString() ?? '0') ?? 0,
        name: user['name']?.toString() ?? '',
        username: user['username']?.toString() ?? user['login_id']?.toString() ?? '',
        email: user['email']?.toString() ?? '',
        mobile: user['mobile']?.toString() ?? user['phone']?.toString() ?? '',
        role: user['role']?.toString().toUpperCase() ?? 'TELECALLER',
        companyName: user['company_name']?.toString() ?? user['company']?.toString() ?? 'CallNow CRM',
        teamName: user['team_name']?.toString() ?? user['team']?.toString() ?? '',
        department: user['department']?.toString() ?? '',
        joinDate: user['join_date']?.toString() ?? user['created_at']?.toString() ?? '',
        profilePhotoUrl: user['profile_photo_url']?.toString(),
      );
    }

    Map<String, dynamic> toJson() => {
      'id': id,
      'name': name,
      'username': username,
      'email': email,
      'mobile': mobile,
      'role': role,
      'company_name': companyName,
      'team_name': teamName,
      'department': department,
      'join_date': joinDate,
      'profile_photo_url': profilePhotoUrl,
    };
  }
  ```

- [ ] **Step 2: Verify model compilation**
  Run: `flutter analyze lib/model/user_profile.dart`
  Expected: No issues found.

---

### Task 2: Implement Config & Offline Cache Service (`ConfigService`)

**Files:**
- Modify: `e:\Projects\FlutterProjects\callnowMobileApp\lib\services\config_service.dart`

**Interfaces:**
- Consumes: `SharedPreferences` keys `auth_token`, `user_data`, `base_url`.
- Produces: Normalized `baseUrl`, `saveAuthData(token, userData)`, `clearAuthData()`, `userDisplayName`, `userEmail`, `userPhone`, `userId`, `userRole`.

- [ ] **Step 1: Verify trailing slash normalization in `ConfigService`**
  Ensure `baseUrl` and `setBaseUrl` consistently remove trailing slashes:
  ```dart
  static String get baseUrl {
    String url = (_initialized ? _cachedBaseUrl : _defaultBaseUrl).trim();
    while (url.endsWith('/')) {
      url = url.substring(0, url.length - 1);
    }
    return url;
  }
  ```

- [ ] **Step 2: Verify `saveAuthData` and `clearAuthData` persistence**
  Ensure full user data map is serialized to JSON string and restored into `_cachedUserData` on `init()`.

- [ ] **Step 3: Verify getters handle missing email/mobile safely**
  Ensure `userEmail` and `userPhone` return empty string or fallback instead of throwing null errors when database columns are null.

---

### Task 3: Implement API Service CRUD Methods (`ApiService`)

**Files:**
- Modify: `e:\Projects\FlutterProjects\callnowMobileApp\lib\services\api_service.dart`

**Interfaces:**
- Produces:
  - **Create**: `ApiService.login({required String email, required String password}) -> Future<Map<String, dynamic>>`
  - **Read**: `ApiService.getProfile() -> Future<Map<String, dynamic>>`
  - **Update**: `ApiService.updateProfile({required String name, String? companyName, String? photoBase64}) -> Future<Map<String, dynamic>>`
  - **Update**: `ApiService.changePassword({required String currentPassword, required String newPassword, required String confirmPassword}) -> Future<void>`
  - **Delete**: `ApiService.logout() -> Future<void>`

- [ ] **Step 1: Enhance `logout()` to call backend session revocation**
  Update `ApiService.logout` to send `POST /auth/logout` to the server before purging local preferences:
  ```dart
  static Future<void> logout() async {
    try {
      if (_token != null && _token!.isNotEmpty) {
        await http.post(
          Uri.parse('$_baseUrl/auth/logout'),
          headers: _headers,
        ).timeout(const Duration(seconds: 4));
      }
    } catch (e) {
      print('[ApiService] Backend logout notification error: $e');
    } finally {
      await ConfigService.clearAuthData();
    }
  }
  ```

- [ ] **Step 2: Confirm `getProfile()` candidate endpoint fallback**
  Confirm `getProfile()` checks candidate endpoints (`$_baseUrl/auth/me`, `$_baseUrl/auth/profile`) and automatically saves fresh profile data into `ConfigService.saveAuthData`.

- [ ] **Step 3: Confirm `updateProfile()` sends only allowed fields**
  Verify `updateProfile()` does NOT include `email` or `mobile` fields in payload to avoid triggering server 400 validation:
  ```dart
  static Future<Map<String, dynamic>> updateProfile({
    required String name,
    String? companyName,
    String? photoBase64,
  }) async {
    final body = <String, dynamic>{
      'name': name.trim(),
      if (companyName != null && companyName.trim().isNotEmpty)
        'company_name': companyName.trim(),
      if (photoBase64 != null && photoBase64.isNotEmpty)
        'profile_photo_base64': photoBase64,
    };
    final response = await http.post(
      Uri.parse('$_baseUrl/auth/profile'),
      headers: _headers,
      body: json.encode(body),
    );
    // Parse response and update ConfigService cache on 200 OK
  }
  ```

- [ ] **Step 4: Verify `changePassword()` handles server response and errors**
  Ensure errors like incorrect current password or short length display the backend's message.

---

### Task 4: Bind Profile Screen UI with Edit Modal & Change Password (`ProfileScreen`)

**Files:**
- Modify: `e:\Projects\FlutterProjects\callnowMobileApp\lib\screens\profile_screen.dart`

**Interfaces:**
- Consumes: `ApiService.getProfile()`, `ApiService.updateProfile()`, `ApiService.changePassword()`, `ApiService.logout()`.
- Produces: Complete profile dashboard with interactive edit sheet and password change sheet.

- [ ] **Step 1: Hydrate UI from local cache on launch**
  In `_fetchProfile(isInitial: true)`, set `_profileData = ConfigService.userData ?? {}` immediately before making the network request to prevent blank screen flashes.

- [ ] **Step 2: Build Edit Profile Bottom Sheet**
  - Name and Company: Editable `TextField`.
  - Registered Email & Registered Mobile: Disabled `TextField(enabled: false)` with `Icons.lock_outline` prefix/suffix and helper text `"Locked • Contact CRM Administrator to change"`.
  - Save button: Calls `ApiService.updateProfile()`, displays loading spinner, and refreshes state on success.

- [ ] **Step 3: Build Change Password Bottom Sheet**
  - Current Password, New Password, Confirm Password with visibility toggle icons.
  - Client-side validation: Minimum 6 characters and password matching.
  - Save button: Calls `ApiService.changePassword()` and shows floating success SnackBar.

- [ ] **Step 4: Build Logout Confirmation Dialog**
  - Prompts user to confirm sign out.
  - On confirm: Calls `await ApiService.logout()` and executes `Navigator.pushNamedAndRemoveUntil(context, '/login', (route) => false)`.

---

### Task 5: Static Analysis & End-to-End Flutter Verification

**Files:**
- Verify: `e:\Projects\FlutterProjects\callnowMobileApp\lib`

- [ ] **Step 1: Run Dart static analyzer across modified files**
  Execute: `flutter analyze lib/services/api_service.dart lib/services/config_service.dart lib/screens/profile_screen.dart lib/model/user_profile.dart`
  Expected: No issues found (0 warnings, 0 errors).

- [ ] **Step 2: Verify in Android Emulator / Device**
  - Launch application.
  - Open Profile Screen -> Verify details populate from `/auth/me`.
  - Tap "Edit Details" -> Change Name -> Save -> Verify updated name reflects instantly.
  - Tap "Change Password" -> Enter current & new password -> Verify password update succeeds.
  - Tap "Logout" -> Verify session terminates and navigates to Login Screen.

