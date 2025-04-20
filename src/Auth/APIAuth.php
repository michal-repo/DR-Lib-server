<?php

namespace DRLib\Auth;

use DRLib\Base\BaseWithDB;
use \Exception;
use \PDO;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;

class APIAuth extends BaseWithDB {

    private string $jwtSecret;
    private int $jwtExpirySeconds;
    private string $jwtIssuer;
    private string $jwtAudience;
    private string $jwtAlgorithm = 'HS256';

    public function __construct() {
        parent::__construct();

        // Load JWT configuration
        $this->jwtSecret = $_ENV['JWT_SECRET_KEY'] ?? null;
        $this->jwtExpirySeconds = (int)($_ENV['JWT_EXPIRY_SECONDS'] ?? 3600);
        $this->jwtIssuer = $_ENV['JWT_ISSUER'] ?? 'DefaultIssuer';
        $this->jwtAudience = $_ENV['JWT_AUDIENCE'] ?? 'DefaultAudience';

        if (empty($this->jwtSecret)) {
            throw new Exception("JWT_SECRET_KEY is not configured in .env", 500);
        }

        // --- Automatically clean up expired tokens ---
        // Note: Consider performance implications on high-traffic sites.
        // A dedicated cron job or probabilistic execution might be better.
        $this->removeExpiredTokens();
        // --- End cleanup ---
    }

    /**
     * Removes expired JWTs from the database.
     * Intended for periodic cleanup.
     *
     * @return void
     */
    private function removeExpiredTokens(): void {
        // --- Performance Consideration ---
        // Running this DELETE on every APIAuth instantiation might be inefficient
        // on high-traffic sites, especially if the jwt_tokens table grows large.
        // Consider running it probabilistically (e.g., 1% of the time) or,
        // preferably, using a dedicated cron job/scheduled task for cleanup.
        /*
        // Example: Probabilistic execution (run ~1% of the time)
        if (random_int(1, 100) !== 1) {
           return;
        }
        */
        // --- End Performance Consideration ---

        $sql = "DELETE FROM jwt_tokens WHERE expires_at <= NOW()";
        try {
            $stmt = $this->db->dbh->prepare($sql);
            $stmt->execute();
            // Optional: Log if rows were deleted
            // $rowCount = $stmt->rowCount();
            // if ($rowCount > 0) { error_log("Cleaned up $rowCount expired JWT tokens."); }
        } catch (\PDOException $e) {
            // Log the error but don't necessarily stop execution,
            // as cleanup failure shouldn't prevent core auth functionality.
            error_log("PDOException during JWT cleanup: " . $e->getMessage());
        }
    }

    /**
     * Authenticates user with email/password and returns a JWT upon success.
     *
     * @param string $email
     * @param string $password
     * @return string The generated JWT.
     * @throws Exception On authentication failure or JWT generation/storage error.
     */
    public function log_in($email, $password): string {
        // Temporarily instantiate Delight\Auth just for credential validation
        $delightAuth = new \Delight\Auth\Auth($this->db->dbh);

        try {
            // Validate credentials using Delight\Auth
            $rememberDuration = null; // Not using Delight's remember me with JWT
            $delightAuth->login($email, $password, $rememberDuration);

            // If login succeeds, get the user ID
            $userId = $delightAuth->getUserId();
            if ($userId === null) {
                throw new Exception('Authentication succeeded but failed to get user ID.', 500);
            }

            // --- Generate JWT ---
            $issuedAt = time();
            $notBefore = $issuedAt;
            $expire = $issuedAt + $this->jwtExpirySeconds;

            $payload = [
                'iss' => $this->jwtIssuer,
                'aud' => $this->jwtAudience,
                'iat' => $issuedAt,
                'nbf' => $notBefore,
                'exp' => $expire,
                'sub' => $userId,
            ];

            $jwt = JWT::encode($payload, $this->jwtSecret, $this->jwtAlgorithm);

            // --- Store JWT in Database ---
            $this->storeJwt($userId, $jwt, $expire);

            return $jwt;

        } catch (\Delight\Auth\InvalidEmailException | \Delight\Auth\InvalidPasswordException | \Delight\Auth\EmailNotVerifiedException $e) {
            throw new Exception($e->getMessage(), 401, $e);
        } catch (\Delight\Auth\TooManyRequestsException $e) {
            throw new Exception('Too many login requests', 429, $e);
        } catch (\Throwable $th) {
            // error_log("Login/JWT Error: " . $th->getMessage());
            throw new Exception('Login failed due to an unexpected error.', 500, $th);
        }
    }

    /**
     * Invalidates a JWT by removing it from the database.
     *
     * @return void
     * @throws Exception If user is not logged in (no valid token found) or DB error occurs.
     */
    public function logOut(): void {
        $jwt = $this->getJwtFromHeader();
        if ($jwt === null) {
            throw new Exception("No token provided for logout.", 400);
        }

        try {
            // Use the specific delete helper method
            $this->deleteJwt($jwt);

        } catch (\PDOException $e) { // Catch potential errors from deleteJwt if it throws them
            // error_log("PDOException during logout: " . $e->getMessage());
            throw new Exception('Logout failed due to a database error.', 500, $e);
        }
    }

    /**
     * Checks if a valid, non-expired, database-stored JWT is present in the request.
     *
     * @return bool
     */
    public function isLoggedIn(): bool {
        try {
            return $this->getUserId() !== null;
        } catch (\Throwable $th) {
            return false;
        }
    }

    /**
     * Validates the JWT from the request header and returns the user ID if valid.
     * Returns null if the token is missing, invalid, expired, or not found in the database.
     *
     * @return int|null
     */
    public function getUserId(): ?int {
        $token = $this->getJwtFromHeader();
        if ($token === null) {
            return null;
        }

        try {
            $decoded = JWT::decode($token, new Key($this->jwtSecret, $this->jwtAlgorithm));

            if (!$this->isJwtStoredAndValid($token)) {
                return null;
            }

            if (isset($decoded->sub) && is_numeric($decoded->sub)) {
                $this->updateJwtLastUsed($token);
                return (int)$decoded->sub;
            }

            return null;

        } catch (ExpiredException $e) {
            // Token expired according to JWT payload
            // Optionally: Clean up this specific expired token from DB
            // $this->deleteJwt($token);
            return null;
        } catch (SignatureInvalidException | BeforeValidException | \DomainException | \InvalidArgumentException | \UnexpectedValueException $e) {
            // Catch specific JWT validation errors
            return null;
        } catch (\Throwable $th) {
            // error_log("Unexpected error during JWT validation: " . $th->getMessage());
            return null;
        }
    }

    // --- Helper Methods ---

    /**
     * Extracts the JWT from the Authorization header.
     * @return string|null
     */
    private function getJwtFromHeader(): ?string {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if ($authHeader === null && function_exists('getallheaders')) {
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        }

        if ($authHeader !== null && stripos($authHeader, 'Bearer ') === 0) {
            return trim(substr($authHeader, 7));
        }
        return null;
    }

    /**
     * Stores the generated JWT in the database.
     * @param int $userId
     * @param string $jwt
     * @param int $expiresAtTimestamp
     * @return void
     * @throws Exception
     */
    private function storeJwt(int $userId, string $jwt, int $expiresAtTimestamp): void {
        $sql = "INSERT INTO jwt_tokens (user_id, token, expires_at, user_agent, token_type)
                VALUES (:user_id, :token, :expires_at, :user_agent, :token_type)";
        try {
            $stmt = $this->db->dbh->prepare($sql);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':token', $jwt, PDO::PARAM_STR);
            $stmt->bindValue(':expires_at', date('Y-m-d H:i:s', $expiresAtTimestamp), PDO::PARAM_STR);
            $stmt->bindValue(':user_agent', $_SERVER['HTTP_USER_AGENT'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':token_type', 'access', PDO::PARAM_STR);

            if (!$stmt->execute()) {
                throw new Exception("Failed to store JWT token.", 500);
            }
        } catch (\PDOException $e) {
            // error_log("PDOException storing JWT: " . $e->getMessage());
            throw new Exception("Failed to store JWT token due to database error.", 500, $e);
        }
    }

    /**
     * Checks if a JWT exists in the database and hasn't expired according to DB time.
     * @param string $jwt
     * @return bool
     */
    private function isJwtStoredAndValid(string $jwt): bool {
        $sql = "SELECT 1 FROM jwt_tokens WHERE token = :token AND expires_at > NOW()";
        try {
            $stmt = $this->db->dbh->prepare($sql);
            $stmt->bindValue(':token', $jwt, PDO::PARAM_STR);
            $stmt->execute();
            return $stmt->fetchColumn() !== false;
        } catch (\PDOException $e) {
            // error_log("PDOException checking JWT validity: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Updates the last_used_at timestamp for a given token.
     * @param string $jwt
     * @return void
     */
    private function updateJwtLastUsed(string $jwt): void {
        $sql = "UPDATE jwt_tokens SET last_used_at = NOW() WHERE token = :token";
        try {
            $stmt = $this->db->dbh->prepare($sql);
            $stmt->bindValue(':token', $jwt, PDO::PARAM_STR);
            $stmt->execute();
        } catch (\PDOException $e) {
            // error_log("PDOException updating JWT last_used: " . $e->getMessage());
        }
    }

    /**
     * Deletes a specific JWT from the database.
     * @param string $jwt
     * @return void
     * @throws \PDOException If DB deletion fails and error handling is desired.
     */
    private function deleteJwt(string $jwt): void {
        // No need for try-catch here if we let PDOExceptions bubble up (e.g., to logOut)
        // or if we handle them in the calling context.
        // If catching here, consider logging the error.
        $sql = "DELETE FROM jwt_tokens WHERE token = :token";
        $stmt = $this->db->dbh->prepare($sql);
        $stmt->bindValue(':token', $jwt, PDO::PARAM_STR);
        $stmt->execute();
        // Optional: Check $stmt->rowCount() if needed
    }

    // --- Registration methods (uncommented as they don't rely on JWT state) ---

    public function isRegisterEnabled(): bool {
        return isset($_ENV["register_enabled"]) && $_ENV["register_enabled"] === "true";
    }

    public function register($email, $password, $username) {
        if (!$this->isRegisterEnabled()) {
            throw new Exception("Registration is disabled.", 503);
        }
        if (empty($username) || preg_match('/[\x00-\x1f\x7f\/:\\\\]/', $username)) {
            throw new Exception("Invalid characters in username or username empty.", 400);
        }

        $delightAuth = new \Delight\Auth\Auth($this->db->dbh);
        try {
            $userId = $delightAuth->registerWithUniqueUsername($email, $password, $username);
            // Return a more informative message or just the ID
            return 'We have signed up a new user with the ID ' . $userId;
        } catch (\Delight\Auth\InvalidEmailException $e) {
            throw new Exception("Invalid email address!", 400);
        } catch (\Delight\Auth\InvalidPasswordException $e) {
            throw new Exception("Invalid password!", 400);
        } catch (\Delight\Auth\UserAlreadyExistsException $e) {
            throw new Exception("Email address already exists!", 409);
        } catch (\Delight\Auth\DuplicateUsernameException $e) {
            throw new Exception("Username already exists!", 409);
        } catch (\Delight\Auth\TooManyRequestsException $e) {
            throw new Exception("Too many registration requests!", 429);
        } catch (\Throwable $th) {
            throw new Exception('Registration failed due to an unexpected error.', 500, $th);
        }
    }
}
