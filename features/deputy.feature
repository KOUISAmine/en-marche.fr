Feature:
  As deputy
  I can send messages to the adherents, see committees and events of my district

  Background:
    Given the following fixtures are loaded:
      | LoadAdherentData  |
      | LoadDistrictData  |

  Scenario Outline: As anonymous I can not access deputy space pages.
    Given I go to "<uri>"
    Then the response status code should be 200
    And I should be on "/connexion"
    Examples:
      | uri                                   |
      | /espace-depute/utilisateurs/message   |
      | /espace-depute/evenements             |
      | /espace-depute/comites                |

  Scenario Outline: As simple adherent I can not access deputy space pages.
    Given I am logged as "carl999@example.fr"
    When I go to "<uri>"
    Then the response status code should be 403
    Examples:
      | uri                                   |
      | /espace-depute/utilisateurs/message   |
      | /espace-depute/evenements             |
      | /espace-depute/comites                |

  Scenario Outline: As deputy of 1st Paris district I can access deputy space pages.
    Given I am logged as "deputy@en-marche-dev.fr"
    When I go to "<uri>"
    Then the response status code should be 200
    Examples:
      | uri                                   |
      | /espace-depute/utilisateurs/message   |
      | /espace-depute/evenements             |
      | /espace-depute/comites                |

  Scenario: As deputy of 1st Paris district I can send message to the adherents.
    Given I am logged as "deputy@en-marche-dev.fr"
    When I am on "/espace-depute/utilisateurs/message"
    Then the "recipient" field should contain "4 marcheurs(s)"
    And the "sender" field should contain "Député PARIS I"

    When I fill in the following:
      | deputy_message[subject]     | |
      | deputy_message[content]     | |
    And I press "Envoyer le message"
    Then I should be on "/espace-depute/utilisateurs/message"
    And I should see 2 ".form__errors" elements
    And I should see "Cette valeur ne doit pas être vide."
    And I should see "Le contenu du message ne doit pas être vide."

    When I fill in the following:
      | deputy_message[subject]     | Message from your deputy       |
      | deputy_message[content]     | Content of a deputy message  |
    And I press "Envoyer le message"
    Then I should be on "/espace-depute/utilisateurs/message"
    And I should see 0 ".form__errors" elements
    And I should see "Votre message a été envoyé avec succès. Il pourrait prendre quelques minutes à s'envoyer."
    And I should have 1 emails
    And I should have 1 email "DeputyMessage" for "jacques.picard@en-marche.fr" with payload:
    """
    {
      "FromEmail": "contact@en-marche.fr",
      "FromName": "Votre d\u00e9put\u00e9 En Marche !",
      "Subject": "Message from your deputy",
      "MJ-TemplateID": "455851",
      "MJ-TemplateLanguage": true,
      "Recipients": [
      {
        "Email": "coordinatrice-cp@en-marche-dev.fr",
        "Name": "Coordinatrice CITIZEN PROJECT",
        "Vars": {
          "deputy_fullname": "D\u00e9put\u00e9 PARIS I",
          "circonscription_name": "Paris, 1\u00e8me circonscription (75-01)",
          "target_message": "Content of a deputy message",
          "target_firstname": "Coordinatrice"
        }
      },
      {
        "Email": "deputy@en-marche-dev.fr",
        "Name": "D\u00e9put\u00e9 PARIS I",
        "Vars": {
          "deputy_fullname": "D\u00e9put\u00e9 PARIS I",
          "circonscription_name": "Paris, 1\u00e8me circonscription (75-01)",
          "target_message": "Content of a deputy message",
          "target_firstname": "D\u00e9put\u00e9"
        }
      },
      {
        "Email": "jacques.picard@en-marche.fr",
        "Name": "Jacques Picard",
        "Vars": {
          "deputy_fullname": "D\u00e9put\u00e9 PARIS I",
          "circonscription_name": "Paris, 1\u00e8me circonscription (75-01)",
          "target_message": "Content of a deputy message",
          "target_firstname": "Jacques"
        }
      },
      {
        "Email": "luciole1989@spambox.fr",
        "Name": "Lucie Olivera",
        "Vars": {
          "deputy_fullname": "D\u00e9put\u00e9 PARIS I",
          "circonscription_name": "Paris, 1\u00e8me circonscription (75-01)",
          "target_message": "Content of a deputy message",
          "target_firstname": "Lucie"
        }
      }],
      "Headers": {
        "Reply-To": "deputy@en-marche-dev.fr"
      }
    }
    """
