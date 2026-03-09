import { useEffect, useMemo, useState } from "react";

const backendBase = import.meta.env.VITE_BACKEND_BASE || "http://localhost:8000";
const MAX_VIDEO_BYTES = 300 * 1024 * 1024;
const MAX_VIDEO_SECONDS = 180;

const emptyForm = {
  first_name: "",
  last_name: "",
  email: "",
  approximate_address: "",
  preferred_contact: "",
  social_media: "",
  other_notes: ""
};

function App() {
  const [loadingUser, setLoadingUser] = useState(true);
  const [loadingForm, setLoadingForm] = useState(false);
  const [savingForm, setSavingForm] = useState(false);
  const [uploadingVideo, setUploadingVideo] = useState(false);
  const [deletingVideo, setDeletingVideo] = useState(false);
  const [user, setUser] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [video, setVideo] = useState({
    has_video: false,
    uploaded_at: null
  });
  const [selectedVideo, setSelectedVideo] = useState(null);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");

  const setErrorMessage = (message) => {
    setSuccess("");
    setError(message);
  };

  const setSuccessMessage = (message) => {
    setError("");
    setSuccess(message);
  };

  const loadUser = async () => {
    setLoadingUser(true);

    try {
      const response = await fetch(`${backendBase}/api/me.php`, {
        credentials: "include"
      });

      if (!response.ok) {
        throw new Error("Benutzerstatus konnte nicht geladen werden.");
      }

      const data = await response.json();
      setUser(data.authenticated ? data.user : null);
    } catch (err) {
      setErrorMessage(err.message || "Unbekannter Fehler.");
    } finally {
      setLoadingUser(false);
    }
  };

  const loadForm = async () => {
    setLoadingForm(true);

    try {
      const response = await fetch(`${backendBase}/api/form.php`, {
        credentials: "include"
      });

      const data = await response.json().catch(() => ({}));
      if (!response.ok) {
        throw new Error(data.error || "Formular konnte nicht geladen werden.");
      }

      setForm({
        ...emptyForm,
        ...(data.form || {})
      });
      setVideo({
        has_video: Boolean(data.video?.has_video),
        uploaded_at: data.video?.uploaded_at || null
      });
      setSelectedVideo(null);
    } catch (err) {
      setErrorMessage(err.message || "Fehler beim Laden des Formulars.");
    } finally {
      setLoadingForm(false);
    }
  };

  useEffect(() => {
    loadUser();

    const params = new URLSearchParams(window.location.search);
    const oauthError = params.get("error");
    const loginStatus = params.get("login");

    if (oauthError) {
      setErrorMessage(`OAuth-Fehler: ${oauthError}`);
    } else if (loginStatus === "success") {
      setSuccessMessage("Login erfolgreich.");
    }

    if (oauthError || loginStatus) {
      window.history.replaceState({}, document.title, window.location.pathname);
    }
  }, []);

  useEffect(() => {
    if (user) {
      loadForm();
      return;
    }

    setForm(emptyForm);
    setVideo({
      has_video: false,
      uploaded_at: null
    });
    setSelectedVideo(null);
  }, [user]);

  const loginWithGoogle = () => {
    window.location.href = `${backendBase}/api/login.php`;
  };

  const logout = async () => {
    try {
      const response = await fetch(`${backendBase}/api/logout.php`, {
        method: "POST",
        credentials: "include"
      });

      if (!response.ok) {
        throw new Error("Logout fehlgeschlagen.");
      }

      setSuccessMessage("Logout erfolgreich.");
      await loadUser();
    } catch (err) {
      setErrorMessage(err.message || "Logout fehlgeschlagen.");
    }
  };

  const onFieldChange = (event) => {
    const { name, value } = event.target;
    setForm((prev) => ({
      ...prev,
      [name]: value
    }));
  };

  const saveForm = async (event) => {
    event.preventDefault();
    setSavingForm(true);
    setError("");
    setSuccess("");

    try {
      const response = await fetch(`${backendBase}/api/form.php`, {
        method: "POST",
        credentials: "include",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify(form)
      });

      const data = await response.json().catch(() => ({}));
      if (!response.ok) {
        throw new Error(data.error || "Formular konnte nicht gespeichert werden.");
      }

      setSuccessMessage("Formular gespeichert.");
      await loadForm();
    } catch (err) {
      setErrorMessage(err.message || "Formular konnte nicht gespeichert werden.");
    } finally {
      setSavingForm(false);
    }
  };

  const getVideoDurationSeconds = (file) =>
    new Promise((resolve, reject) => {
      const objectUrl = URL.createObjectURL(file);
      const videoElement = document.createElement("video");
      videoElement.preload = "metadata";
      videoElement.onloadedmetadata = () => {
        const duration = Number(videoElement.duration || 0);
        URL.revokeObjectURL(objectUrl);
        resolve(duration);
      };
      videoElement.onerror = () => {
        URL.revokeObjectURL(objectUrl);
        reject(new Error("Videodauer konnte nicht gelesen werden."));
      };
      videoElement.src = objectUrl;
    });

  const uploadVideo = async () => {
    if (!selectedVideo) {
      setErrorMessage("Bitte zuerst ein Video auswaehlen.");
      return;
    }

    if (selectedVideo.size > MAX_VIDEO_BYTES) {
      setErrorMessage("Video ist groesser als 300MB.");
      return;
    }

    setUploadingVideo(true);
    setError("");
    setSuccess("");

    try {
      const duration = await getVideoDurationSeconds(selectedVideo);
      if (Number.isFinite(duration) && duration > MAX_VIDEO_SECONDS) {
        throw new Error("Video ist laenger als 3 Minuten.");
      }

      const formData = new FormData();
      formData.append("video", selectedVideo);

      const response = await fetch(`${backendBase}/api/video-upload.php`, {
        method: "POST",
        credentials: "include",
        body: formData
      });

      const data = await response.json().catch(() => ({}));
      if (!response.ok) {
        throw new Error(data.error || "Video konnte nicht gespeichert werden.");
      }

      setSuccessMessage("Video gespeichert.");
      await loadForm();
    } catch (err) {
      setErrorMessage(err.message || "Video konnte nicht gespeichert werden.");
    } finally {
      setUploadingVideo(false);
    }
  };

  const deleteVideo = async () => {
    setDeletingVideo(true);
    setError("");
    setSuccess("");

    try {
      const response = await fetch(`${backendBase}/api/video-delete.php`, {
        method: "POST",
        credentials: "include"
      });

      const data = await response.json().catch(() => ({}));
      if (!response.ok) {
        throw new Error(data.error || "Video konnte nicht geloescht werden.");
      }

      setSuccessMessage("Video geloescht.");
      await loadForm();
    } catch (err) {
      setErrorMessage(err.message || "Video konnte nicht geloescht werden.");
    } finally {
      setDeletingVideo(false);
    }
  };

  const videoSrc = useMemo(() => {
    if (!video.has_video) {
      return "";
    }

    const token = video.uploaded_at ? encodeURIComponent(video.uploaded_at) : Date.now();
    return `${backendBase}/api/video.php?v=${token}`;
  }, [video.has_video, video.uploaded_at]);

  return (
    <div className="app">
      <header className="top-bar">
        {user ? (
          <button className="logout-btn" onClick={logout}>
            Logout
          </button>
        ) : null}
      </header>

      <main className="main">
        <section className="card">
          <h1 className="title">Google Formular</h1>

          {loadingUser ? <p>Lade Benutzerstatus...</p> : null}
          {error ? <p className="error">{error}</p> : null}
          {success ? <p className="success">{success}</p> : null}

          {!loadingUser && !user ? (
            <button className="login-btn" onClick={loginWithGoogle}>
              Uber Google einloggen
            </button>
          ) : null}

          {!loadingUser && user ? (
            <form className="profile-form" onSubmit={saveForm}>
              {loadingForm ? <p>Lade Formular...</p> : null}

              <div className="row row-2">
                <label className="field">
                  <span>Vorname</span>
                  <input
                    type="text"
                    name="first_name"
                    value={form.first_name}
                    onChange={onFieldChange}
                    placeholder="Vorname"
                  />
                </label>

                <label className="field">
                  <span>Nachname</span>
                  <input
                    type="text"
                    name="last_name"
                    value={form.last_name}
                    onChange={onFieldChange}
                    placeholder="Nachname"
                  />
                </label>
              </div>

              <label className="field">
                <span>E-Mail</span>
                <input
                  type="email"
                  name="email"
                  value={form.email}
                  onChange={onFieldChange}
                  placeholder="name@beispiel.de"
                />
              </label>

              <label className="field">
                <span>Ungefaehre Adresse</span>
                <input
                  type="text"
                  name="approximate_address"
                  value={form.approximate_address}
                  onChange={onFieldChange}
                  placeholder="z. B. Stadtteil, Stadt, Region"
                />
              </label>

              <label className="field">
                <span>Wie erreicht man dich am besten?</span>
                <input
                  type="text"
                  name="preferred_contact"
                  value={form.preferred_contact}
                  onChange={onFieldChange}
                  placeholder="z. B. E-Mail, Telefon, WhatsApp"
                />
              </label>

              <label className="field">
                <span>Social-Media-Namen</span>
                <input
                  type="text"
                  name="social_media"
                  value={form.social_media}
                  onChange={onFieldChange}
                  placeholder="z. B. Instagram/TikTok Name"
                />
              </label>

              <label className="field">
                <span>Sonstiges</span>
                <textarea
                  name="other_notes"
                  value={form.other_notes}
                  onChange={onFieldChange}
                  rows={4}
                  placeholder="Weitere Informationen..."
                />
              </label>

              <div className="video-section">
                <h2>Vorstellungs-Video</h2>
                <p className="hint">
                  Maximal 3 Minuten und maximal 300MB. Aufnahme/Upload ueber Dateiauswahl.
                </p>

                <label className="field">
                  <span>Video aufnehmen oder hochladen</span>
                  <input
                    type="file"
                    accept="video/*"
                    capture="user"
                    onChange={(event) => {
                      const file = event.target.files?.[0] || null;
                      setSelectedVideo(file);
                    }}
                  />
                </label>

                {selectedVideo ? (
                  <p className="hint">
                    Ausgewaehlt: {selectedVideo.name} ({(selectedVideo.size / (1024 * 1024)).toFixed(1)} MB)
                  </p>
                ) : null}

                <div className="actions inline">
                  <button
                    type="button"
                    className="secondary-btn"
                    onClick={uploadVideo}
                    disabled={uploadingVideo || deletingVideo}
                  >
                    {uploadingVideo ? "Upload laeuft..." : "Video speichern"}
                  </button>

                  {video.has_video ? (
                    <button
                      type="button"
                      className="danger-btn"
                      onClick={deleteVideo}
                      disabled={uploadingVideo || deletingVideo}
                    >
                      {deletingVideo ? "Loesche..." : "Video loeschen"}
                    </button>
                  ) : null}
                </div>

                {video.has_video ? (
                  <video className="video-player" controls src={videoSrc} crossOrigin="use-credentials" />
                ) : (
                  <p className="hint">Noch kein Video gespeichert.</p>
                )}
              </div>

              <div className="actions">
                <button type="submit" className="primary-btn" disabled={savingForm || loadingForm}>
                  {savingForm ? "Speichere..." : "Speichern"}
                </button>
              </div>
            </form>
          ) : null}
        </section>
      </main>
    </div>
  );
}

export default App;
