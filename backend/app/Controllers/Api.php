<?php

namespace App\Controllers;

final class Api extends BaseController
{
    public function session()
    {
        helper("form");
        $id = session()->get("user_id");
        $user = $id
            ? db_connect()
                ->table("users")
                ->select("id,name,email,role")
                ->where(["id" => $id, "active" => 1])
                ->get()
                ->getRowArray()
            : null;
        return $this->response
            ->setHeader("Cache-Control", "no-store")
            ->setJSON([
                "user" => $user,
                "environment" =>
                    getenv("BATCH_TRACER_E2E") === "1" ? "e2e" : "operations",
                "csrf" => ["header" => csrf_header(), "token" => csrf_hash()],
            ]);
    }

    public function login()
    {
        if (
            !service("throttler")->check(
                "login-" .
                    (getenv("BATCH_TRACER_E2E") === "1" ? "e2e-" : "ops-") .
                    hash("sha256", $this->request->getIPAddress()),
                10,
                60,
            )
        ) {
            return $this->response->setStatusCode(429)->setJSON([
                "message" =>
                    "Too many login attempts. Try again in one minute.",
            ]);
        }
        try {
            $input = $this->request->getJSON(true) ?? [];
        } catch (\Throwable $e) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON(["message" => "Malformed JSON request."]);
        }
        if (!is_array($input)) {
            return $this->response
                ->setStatusCode(422)
                ->setJSON(["message" => "A JSON object is required."]);
        }
        $email = $input["email"] ?? "";
        $password = $input["password"] ?? "";
        if (!is_string($email) || !is_string($password)) {
            return $this->response
                ->setStatusCode(422)
                ->setJSON(["message" => "Email and password must be strings."]);
        }
        $user = db_connect()
            ->table("users")
            ->where(["email" => $email, "active" => 1])
            ->get()
            ->getRowArray();
        if (!$user || !password_verify($password, $user["password_hash"])) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON(["message" => "Invalid credentials."]);
        }
        session()->regenerate(true);
        session()->set("user_id", (int) $user["id"]);
        return $this->session();
    }

    public function logout()
    {
        session()->destroy();
        return $this->response->setJSON(["message" => "Signed out."]);
    }

    public function catalog(string $type)
    {
        $id = session()->get("user_id");
        $user = $id
            ? db_connect()
                ->table("users")
                ->where(["id" => $id, "active" => 1])
                ->get()
                ->getRowArray()
            : null;
        if (!$user) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON(["message" => "Authentication required."]);
        }
        if (
            !in_array(
                $type,
                ["products", "customers", "locations", "ingredient_lots"],
                true,
            )
        ) {
            return $this->response
                ->setStatusCode(404)
                ->setJSON(["message" => "Unknown catalog."]);
        }
        $page = max(1, (int) $this->request->getGet("page"));
        $query = db_connect()->table($type);
        return $this->response->setJSON([
            "data" => $query
                ->orderBy("id")
                ->get(50, ($page - 1) * 50)
                ->getResultArray(),
            "page" => $page,
            "per_page" => 50,
        ]);
    }
}
