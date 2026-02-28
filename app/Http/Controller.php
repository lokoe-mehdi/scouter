<?php

namespace App\Http;

use App\Auth\Auth;

/**
 * Classe de base abstraite pour les controllers
 * 
 * Fournit les méthodes communes pour les réponses HTTP et la validation.
 * Tous les controllers doivent étendre cette classe.
 * 
 * @package    Scouter
 * @subpackage Http
 * @author     Mehdi Colin
 * @version    1.0.0
 */
abstract class Controller
{
    /**
     * Instance d'authentification
     * 
     * @var Auth
     */
    protected Auth $auth;

    /**
     * ID de l'utilisateur connecté
     * 
     * @var int|null
     */
    protected ?int $userId;

    /**
     * Constructeur
     * 
     * @param Auth $auth Instance d'authentification
     */
    public function __construct(Auth $auth)
    {
        $this->auth = $auth;
        $this->userId = $auth->getCurrentUserId();
    }

    /**
     * Envoie une réponse JSON
     * 
     * @param array<string, mixed> $data   Données à encoder
     * @param int                  $status Code de statut HTTP
     * 
     * @return void
     */
    protected function json(array $data, int $status = 200): void
    {
        Response::json($data, $status);
    }

    /**
     * Envoie une réponse de succès
     * 
     * @param array<string, mixed> $data    Données additionnelles
     * @param string|null          $message Message de succès
     * 
     * @return void
     */
    protected function success(array $data = [], string $message = null): void
    {
        Response::success($data, $message);
    }

    /**
     * Envoie une réponse d'erreur
     * 
     * @param string $message Message d'erreur
     * @param int    $status  Code de statut HTTP
     * 
     * @return void
     */
    protected function error(string $message, int $status = 400): void
    {
        Response::error($message, $status);
    }

    /**
     * Valide les données de la requête selon des règles
     * 
     * Règles supportées: required, email, numeric, min:N
     * 
     * @param Request              $request Requête HTTP
     * @param array<string,string> $rules   Règles de validation (field => 'rule1|rule2')
     * 
     * @return array<string, mixed> Données validées
     */
    protected function validate(Request $request, array $rules): array
    {
        $errors = [];
        $data = [];

        foreach ($rules as $field => $rule) {
            $value = $request->get($field);
            $rulesList = explode('|', $rule);

            foreach ($rulesList as $r) {
                if ($r === 'required' && ($value === null || $value === '')) {
                    $errors[$field] = "Le champ $field est requis";
                    break;
                }

                if ($r === 'email' && $value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field] = "Le champ $field doit être un email valide";
                    break;
                }

                if ($r === 'numeric' && $value && !is_numeric($value)) {
                    $errors[$field] = "Le champ $field doit être numérique";
                    break;
                }

                if (preg_match('/^min:(\d+)$/', $r, $m) && $value && strlen($value) < (int)$m[1]) {
                    $errors[$field] = "Le champ $field doit contenir au moins {$m[1]} caractères";
                    break;
                }
            }

            if (!isset($errors[$field])) {
                $data[$field] = $value;
            }
        }

        if (!empty($errors)) {
            Response::error(implode(', ', $errors), 400);
        }

        return $data;
    }
}
