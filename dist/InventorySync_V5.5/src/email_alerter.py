import smtplib
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart
import logging
from datetime import datetime

logger = logging.getLogger(__name__)

class EmailAlerter:
    def __init__(self, config):
        self.smtp_server = config.get('smtp_server')
        self.smtp_port = config.get('smtp_port')
        self.sender_email = config.get('sender_email')
        self.sender_password = config.get('sender_password')
        self.recipient_emails = config.get('recipient_emails', [])

    def send_alert(self, subject, message):
        """Send an email alert."""
        if not self.recipient_emails:
            logger.warning("No recipient emails configured for alerts.")
            return

        try:
            msg = MIMEMultipart()
            msg['From'] = self.sender_email
            msg['To'] = ", ".join(self.recipient_emails)
            msg['Subject'] = f"[Inventory Sync Alert] {subject}"

            body = f"""
            Time: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}
            
            Message:
            {message}
            
            Please check the system logs for more details.
            """
            msg.attach(MIMEText(body, 'plain'))

            server = smtplib.SMTP(self.smtp_server, self.smtp_port)
            server.starttls()
            server.login(self.sender_email, self.sender_password)
            text = msg.as_string()
            server.sendmail(self.sender_email, self.recipient_emails, text)
            server.quit()
            
            logger.info(f"Alert email sent: {subject}")
        except Exception as e:
            logger.error(f"Failed to send alert email: {e}")
